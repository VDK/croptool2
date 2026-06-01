<?php

namespace CropTool;

use CropTool\Auth\AuthServiceInterface;
use CropTool\Errors\ApiError;
use DI\FactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Simple MediaWiki API client
 */
class ApiService
{

    protected $endpoint;
    protected $container;
    protected $auth;
    protected $logger;
    protected $userAgent;
    protected $site;
    protected $factory;
    public $calls = 0;

    public function __construct(FactoryInterface $factory, LoggerInterface $logger, AuthServiceInterface $auth, Config $config, $site = 'commons.wikimedia.org')
    {
        $this->factory = $factory;
        $this->logger = $logger;
        $this->auth = $auth;
        $this->site = $site;
        $this->endpoint = 'https://' . $this->site . '/w/api.php';
        $this->userAgent = $config->get('userAgent', 'CropTool');
    }

    public function getSite()
    {
        return $this->site;
    }

    /**
     * Make a request to the MW API
     *
     * @param array $args
     * @param bool $multipart
     * @return stdClass
     */
    public function request($args, $multipart = false, $signed = true)
    {
        $args['format'] = 'json';

        $this->calls += 1;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, true);
        if ($multipart == true) {
            $oauthHeader = $this->auth->signRequestAndReturnHeader('POST', $this->endpoint);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
        } else {
            $oauthHeader = $this->auth->signRequestAndReturnHeader('POST', $this->endpoint, $args);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($args));
        }

        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        if ($signed) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($oauthHeader));
        }
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $data = curl_exec($ch);

        if (!$data) {
            header("HTTP/1.1 500 Internal Server Error");
            throw new \RuntimeException('Curl error: ' . htmlspecialchars(curl_error($ch)));
        }

        curl_close($ch);

        $data = json_decode($data);
        if (isset($data->error)) {
            throw new ApiError('[api] Received error: ' . $data->error->code . ' : ' . $data->error->info);
        }

        return $data;
    }

    /**
     * @param $title
     * @return WikiText
     */
    public function getWikitext($title)
    {
        $response = $this->request([
            'action' => 'parse',
            'prop' => 'wikitext',
            'format' => 'json',
            'page' => 'File:' . $title
        ], false, false);

        return $this->factory->make(WikiText::class, [
            'text' => $response->parse->wikitext->{'*'}
            ]);
    }

    /**
     * @param $title
     * @return QueryResponse
     */
    public function getImageinfo($title, $namespace='File:')
    {
        $response = $this->request([
            'action' => 'query',
            'prop' => 'imageinfo|categories|info',
            'format' => 'json',
            'inprop' => 'protection',
            'iiprop' => 'url|size|sha1|mime',
            'iilimit' => '1',
            'titles' => $namespace . $title
        ]);

        return $this->factory->make(QueryResponse::class, [
            'response' => $response->query
        ]);
    }

    public function getDepictsStatements($mediaInfoId)
    {
        if (!$mediaInfoId) {
            return [];
        }

        $response = $this->request([
            'action' => 'wbgetclaims',
            'entity' => $mediaInfoId,
            'property' => 'P180',
        ]);

        return $response->claims->P180 ?? [];
    }

    public function getEntityTerms($ids, $languages = 'en|mul|de|fr')
    {
        if (!count($ids)) {
            return [];
        }

        $response = $this->request([
            'action' => 'wbgetentities',
            'ids' => implode('|', $ids),
            'props' => 'labels|descriptions',
            'languages' => $languages,
        ]);

        $languageCodes = explode('|', $languages);
        $terms = [];
        foreach ($response->entities ?? [] as $id => $entity) {
            $labels = $this->entityTermValues($entity, 'labels', $languageCodes);
            $descriptions = $this->entityTermValues($entity, 'descriptions', $languageCodes);
            $terms[$id] = [
                'label' => $this->firstEntityTerm($labels, $languageCodes, $id),
                'description' => $this->firstEntityTerm($descriptions, $languageCodes, null, ['mul']),
                'labels' => $labels,
                'descriptions' => $descriptions,
            ];
        }

        return $terms;
    }

    private function entityTermValues($entity, $termType, array $languageCodes)
    {
        $values = [];
        foreach ($languageCodes as $language) {
            if (isset($entity->{$termType}->{$language}->value)) {
                $values[$language] = $entity->{$termType}->{$language}->value;
            }
        }
        return $values;
    }

    private function firstEntityTerm(array $terms, array $languageCodes, $default = null, array $skipLanguages = [])
    {
        foreach ($languageCodes as $language) {
            if (in_array($language, $skipLanguages)) {
                continue;
            }
            if (isset($terms[$language])) {
                return $terms[$language];
            }
        }
        return $default;
    }

    /**
     * Request an edit token
     * Returns the edit token, or FALSE on failure
     */
    public function getEditToken()
    {
        $response = $this->request([
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'csrf'
        ]);

        return $response->query->tokens->csrftoken;
    }


    /**
     * @param string $title
     * @param string $filename
     * @param string $summary
     * @param string|null $text
     * @param bool $ignoreWarnings
     * @return array
     */
    public function upload($title, $filename, $summary, $text=null, $ignoreWarnings=false)
    {
        $token = $this->getEditToken();

        $args = [
            'action' => 'upload',
            'format' => 'json',
            'filename' => $title,
            'token' => $token,
            'comment' => $summary,
            'file' => new \CURLFile($filename),
        ];
        if ($ignoreWarnings) {
            $args['ignorewarnings'] = '1';
        }
        if (!is_null($text)) {
            $args['text'] = $text;
        }
        return $this->request($args, true)->upload;
    }

    /**
     * @param $title
     * @param $text
     * @param $summary
     * @return array
     */
    public function savePage($title, $text, $summary)
    {
        return $this->request([
            'action' => 'edit',
            'format' => 'json',
            'summary' => $summary,
            'token' => $this->getEditToken(),
            'title' => $title,
            'text' => $text
        ]);
    }

    /**
     * Create a new Wikibase claim
     *
     * @param string $entity (e.g. 'Q42')
     * @param string $property (e.g. 'P18')
     * @param string $value (e.g. 'Test.jpg')
     * @param string $snaktype (defaults to 'value')
     */
    public function createClaim($entity, $property, $value, $snaktype='value')
    {
        $token = $this->getEditToken();

        return $this->request([
            'action' => 'wbcreateclaim',
            'entity' => $entity,
            'property' => $property,
            'snaktype' => $snaktype,
            'value' => $value,
            'token' => $token,
        ]);
    }

    /**
     * Get the claims for a given entity, filtered by property.
     *
     * @param string $entity (e.g. 'Q42')
     * @param string $property (e.g. 'P18')
     */
    public function getClaimsByProperty($entity, $property)
    {
        $response = $this->request([
            'action' => 'wbgetclaims',
            'entity' => $entity,
        ]);

        if (!isset($response->claims->{$property})) {
            return [];
        }

        return $response->claims->{$property};
    }

    /**
     * Get data for one or more Wikidata entities.
     *
     * @param string $entity (e.g. 'Q42' or 'Q42|Q17')
     */
    public function getEntities($entities)
    {
        $response = $this->request([
            'action' => 'wbgetentities',
            'ids' => $entities,
        ]);

        if (isset($response->error)) {
            throw new NoSuchEntity();
        }

        return $response->entities;
    }
}
