<?php

namespace Symfony\Component\Mailer\Bridge\AcumbaMail\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class AcumbaMailTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();
        $authToken = $this->getAuthToken($scheme, $dsn);
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();

        if ('acumba+api' === $scheme) {
            return (new AcumbaMailApiTransport($authToken, $this->client, $this->dispatcher, $this->logger))->setHost($host);
        }


        throw new UnsupportedSchemeException($dsn, 'acumba', $this->getSupportedSchemes());
    }

    protected function getAuthToken(string $scheme, string $dsn): string {
        $authToken = explode("@", $dsn);
        return str_replace($scheme, "", $this->$authToken[1]);
    }

    protected function getSupportedSchemes(): array
    {
        return ['acumba+api'];
    }
}