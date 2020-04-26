<?php

use Goutte\Client;

class PegassClient
{
    private $client;
    private $authenticated = false;

    public function __construct()
    {
        $this->client = new Client([
            'cookies' => true,
            'allow_redirects' => true,
        ]);
    }

    /**
     * @param string $identifier
     *
     * @return array
     */
    public function getVolunteers(string $identifier): array
    {
        $pages = [];

        do {
            $endpoint = str_replace([
                '%page%',
                '%identifier%',
            ], [
                ($data['page'] ?? -1) + 1,
                $identifier,
            ], 'https://pegass.croix-rouge.fr/crf/rest/utilisateur?page=%page%&pageInfo=true&perPage=50&searchType=benevoles&structure=%identifier%&withMoyensCom=true');

            $pages[] = $data = $this->get($endpoint);
        } while (count($data['list']) && $data['page'] < $data['pages']);

        return $pages;
    }

    /**
     * @param string $url
     *
     * @return array
     */
    private function get(string $url): array
    {
        $this->authenticate();

        $this->client->request('GET', $url);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function authenticate()
    {
        if ($this->isAuthenticated()) {
            return;
        }

        if (!getenv('PEGASS_LOGIN') || !getenv('PEGASS_PASSWORD')) {
            throw new \LogicException('Credentials are required to access Pegass API.');
        }

        $crawler = $this->client->request('GET', 'https://pegass.croix-rouge.fr/');
        $form = $crawler->selectButton('Ouverture de session')->form();

        $crawler = $this->client->submit($form, [
            'username' => getenv('PEGASS_LOGIN'),
            'password' => getenv('PEGASS_PASSWORD'),
        ]);

        $form = $crawler->selectButton('Continue')->form();

        $this->client->submit($form);

        $this->authenticated = true;
    }

    /**
     * @return bool
     */
    private function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function fetchEmails(array $contact): array
    {
        $emailKeys = ['MAIL', 'MAILDOM', 'MAILTRAV'];

        // Filter out keys that are not emails
        $contact = array_filter($contact, function ($data) use ($emailKeys) {
            return in_array($data['moyenComId'] ?? [], $emailKeys)
                && preg_match('/^.+\@.+\..+$/', $data['libelle'] ?? false);
        });

        // Order emails
        usort($contact, function ($a, $b) use ($emailKeys) {
            return array_search($a['moyenComId'], $emailKeys) <=> array_search($b['moyenComId'], $emailKeys);
        });

        $emails = array_filter(array_map(function ($info) {
            return $info['libelle'] ?? false;
        }, $contact));

        usort($emails, function ($a, $b) {
            // Red cross emails should be put last
            if (false !== stripos($a, '@croix-rouge.fr')) {
                return 1;
            }

            if (false !== stripos($b, '@croix-rouge.fr')) {
                return -1;
            }

            return 0;
        });

        return $emails;
    }
}