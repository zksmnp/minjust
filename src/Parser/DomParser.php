<?php

declare(strict_types=1);

namespace SomeWork\Minjust\Parser;

use Exception;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\Collection;
use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\NotLoadedException;
use SomeWork\Minjust\Entity\DetailLawyer;
use SomeWork\Minjust\Entity\LawFormation;
use SomeWork\Minjust\Entity\Lawyer;
use SomeWork\Minjust\Entity\Location;
use SomeWork\Minjust\Exception\BlockNotFoundException;
use SomeWork\Minjust\Exception\RuntimeException;
use SomeWork\Minjust\FindRequest;
use SomeWork\Minjust\FindResponse;

/**
 * @see \SomeWork\Minjust\Tests\Unit\DomParserTest
 */
class DomParser implements ParserInterface
{
    /**
     * @var string
     */
    protected const PAGINATION_BLOCK_SELECTOR = 'ul.pagination';

    /**
     * @var string
     */
    protected const CURRENT_PAGE_SELECTOR = 'li.active';

    /**
     * @var string
     */
    protected const PAGINATION_STEP_SELECTOR = 'li';

    /**
     * @var string
     */
    protected const LAWYERS_LIST_BLOCK_SELECTOR = 'table.persons > tbody > tr';

    /**
     * @var string
     */
    protected const LAWYER_DETAIL_SELECTOR = '.floating > p.row';

    /**
     * @var string
     */
    protected const LAWYER_DETAIL_NAME_FIELD = 'label';

    /**
     * @var string
     */
    protected const LOCATIONS_BLOCK_SELECTOR = 'select#' . FindRequest::TERRITORIAL_SUBJECT . ' > option';

    public function list(string $body): FindResponse
    {
        $dom = (new Dom())->loadStr($body);

        return (new FindResponse())
            ->setPage($this->getCurrentPage($dom))
            ->setTotalPage($this->getTotalPage($dom))
            ->setLawyers($this->getListLawyers($dom));
    }

    protected function getCurrentPage(Dom $dom): int
    {
        try {
            /**
             * @var HtmlNode|null $block
             */
            $block = $this
                ->getPagination($dom)
                ->find(static::CURRENT_PAGE_SELECTOR, 0);
        } catch (ChildNotFoundException $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (null === $block) {
            throw new BlockNotFoundException(static::CURRENT_PAGE_SELECTOR);
        }

        try {
            return (int) $block->firstChild()->text();
        } catch (ChildNotFoundException $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    protected function getTotalPage(Dom $dom): int
    {
        try {
            /* @noinspection NullPointerExceptionInspection */
            $collection = $this
                ->getPagination($dom)
                ->find(static::PAGINATION_STEP_SELECTOR)
                ->toArray();
        } catch (ChildNotFoundException $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (0 === count($collection)) {
            return 1;
        }

        /**
         * @var HtmlNode $last
         */
        $last = end($collection);

        try {
            /**
             * @var HtmlNode|null $link
             */
            $link = $last->find('a', 0);
        } catch (ChildNotFoundException $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if ($link) {
            $href = (string) $link->getAttribute('href');
            $matches = [];
            preg_match('/page=([\d]+)/', $href, $matches);

            return (int) $matches[1];
        }

        return $this->getCurrentPage($dom);
    }

    protected function getPagination(Dom $dom): HtmlNode
    {
        static $parsedDom = null;
        static $pagination = null;
        if ($dom !== $parsedDom) {
            $parsedDom = $dom;

            try {
                $pagination = $dom->find(static::PAGINATION_BLOCK_SELECTOR, 0);
            } catch (Exception $exception) {
                throw new RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
            }

            if (null === $pagination) {
                throw new BlockNotFoundException(static::PAGINATION_BLOCK_SELECTOR);
            }
        }

        return $pagination;
    }

    /**
     * @param Dom $dom
     *
     * @return Lawyer[]
     * @throws ChildNotFoundException
     * @throws NotLoadedException
     */
    protected function getListLawyers(Dom $dom): array
    {
        $data = [];
        /**
         * @var Dom\HtmlNode[]|Collection $nodes
         */
        $nodes = $dom->find(static::LAWYERS_LIST_BLOCK_SELECTOR);
        $locations = $this->getLocations($dom);

        foreach ($nodes as $node) {
            /**
             * @var Dom\HtmlNode[]|Collection $tds
             */
            $tds = $node->find('td');

            $registerNumber = trim($tds[0]->text());
            $certificateNumber = trim($tds[3]->text());
            $fullName = trim($tds[1]->text(true));
            $url = trim($tds[1]->firstChild()->getAttribute('href'));
            $status = trim($tds[4]->text());
            $location = $this->getLocationByRegisterNumber($registerNumber, $locations);

            if (null === $location) {
                continue;
            }

            $data[] = (new Lawyer())
                ->setRegisterNumber($registerNumber)
                ->setCertificateNumber($certificateNumber)
                ->setFullName($fullName)
                ->setUrl($url)
                ->setLocation($location)
                ->setStatus($status);
        }

        return $data;
    }

    public function detail(string $body): DetailLawyer
    {
        $dom = (new Dom())->loadStr($body);

        /**
         * @var Dom\HtmlNode[] $nodes
         */
        $nodes = $dom->find(static::LAWYER_DETAIL_SELECTOR)->toArray();

        $nodes = array_filter($nodes, static function (HtmlNode $htmlNode) {
            return strpos($htmlNode->getAttribute('class'), static::LAWYER_DETAIL_NAME_FIELD) === false;
        });

        $nodes = array_values($nodes);

        $lawyer = (new DetailLawyer())
            ->setChamberOfLaw(trim($nodes[2]->text()));

        if (($organizationForm = trim($nodes[3]->text())) !== '') {
            $lawyer->setLawFormation(
                (new LawFormation())
                    ->setOrganizationalForm($organizationForm)
                    ->setName(trim($nodes[4]->text()))
                    ->setAddress(trim($nodes[5]->text()))
                    ->setPhone(trim($nodes[6]->text()))
                    ->setEmail(trim($nodes[7]->text()))
            );
        }

        return $lawyer;
    }

    /**
     * @param string     $registerNumber
     * @param Location[] $locations
     *
     * @return Location|null
     * @return Location|null
     */
    protected function getLocationByRegisterNumber(string $registerNumber, array $locations): ?Location
    {
        [$locationId,] = explode('/', $registerNumber);

        foreach ($locations as $location) {
            if ($location->getId() === $locationId) {
                return $location;
            }
        }

        return null;
    }

    protected function getLocations(Dom $dom)
    {
        /**
         * @var Dom\HtmlNode[] $nodes
         */
        $nodes = $dom->find(static::LOCATIONS_BLOCK_SELECTOR)->toArray();

        $nodes = array_filter(
            $nodes,
            static function (HtmlNode $htmlNode) {
                return $htmlNode->getAttribute('value');
            }
        );

        return array_map(
            static function (HtmlNode $htmlNode) {
                $id = trim($htmlNode->getAttribute('value'));

                $name = $htmlNode->text();
                $name = str_replace('[' . $id . ']', '', $name);
                $name = trim($name);

                return (new Location())
                    ->setId($id)
                    ->setName($name);
            },
            $nodes
        );
    }
}
