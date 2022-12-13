<?php

namespace Symfony\Component\Mailer\Bridge\AcumbaMail\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AcumbaMailApiTransport extends AbstractApiTransport
{
    private const HOST = 'acumbamail.com';

    private string $authtoken;

    public function __construct(string $authtoken, HttpClientInterface $client = null, EventDispatcherInterface $dispatcher = null, LoggerInterface $logger = null)
    {
        $this->authtoken = $authtoken;

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('acumba+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', sprintf('https://%s/api/1/sendOne', $this->getEndpoint()), [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'query' => [
                'body' => $this->getPayload($email, $envelope),
                'auth_token' => $this->authtoken,
                'to_email' => $this->formatAddresses($this->getRecipients($email, $envelope)),
                'from_email' => $this->formatAddress($envelope->getSender()),
                'subject' => $email->getSubject()
            ],

        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException(sprintf('Unable to send an email: "%s" (code %d).', $response->getContent(false), $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Mailjet server.', $response, 0, $e);
        }

        if (200 !== $statusCode) {
            $errorDetails = $result['Messages'][0]['Errors'][0]['ErrorMessage'] ?? $response->getContent(false);

            throw new HttpTransportException(sprintf('Unable to send an email: "%s" (code %d).', $errorDetails, $statusCode), $response);
        }

        // The response needs to contains a 'Messages' key that is an array
        if (!\array_key_exists('Messages', $result) || !\is_array($result['Messages']) || 0 === \count($result['Messages'])) {
            throw new HttpTransportException(sprintf('Unable to send an email: "%s" malformed api response.', $response->getContent(false)), $response);
        }

        $sentMessage->setMessageId($response->getHeaders(false)['x-mj-request-guid'][0]);

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $html = $email->getHtmlBody();
        if (null !== $html && \is_resource($html)) {
            if (stream_get_meta_data($html)['seekable'] ?? false) {
                rewind($html);
            }
            $html = stream_get_contents($html);
        }
        [$attachments, $inlines, $html] = $this->prepareAttachments($email, $html);

        $message = [
            'From' => $this->formatAddress($envelope->getSender()),
            'To' => $this->formatAddresses($this->getRecipients($email, $envelope)),
            'Subject' => $email->getSubject()
        ];
        if ($emails = $email->getCc()) {
            $message['Cc'] = $this->formatAddresses($emails);
        }
        if ($emails = $email->getBcc()) {
            $message['Bcc'] = $this->formatAddresses($emails);
        }
        if ($emails = $email->getReplyTo()) {
            if (1 < $length = \count($emails)) {
                throw new TransportException(sprintf('Mailjet\'s API only supports one Reply-To email, %d given.', $length));
            }
            $message['ReplyTo'] = $this->formatAddress($emails[0]);
        }
        if ($email->getTextBody()) {
            $message['TextPart'] = $email->getTextBody();
        }
        if ($html) {
            $message['HTMLPart'] = $html;
        }


        return [
            'Messages' => [$message],
        ];
    }

    private function formatAddresses(array $addresses): array
    {
        return array_map($this->formatAddress(...), $addresses);
    }

    private function formatAddress(Address $address): array
    {
        return [
            'Email' => $address->getAddress(),
            'Name' => $address->getName(),
        ];
    }


    private function getEndpoint(): ?string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }

}