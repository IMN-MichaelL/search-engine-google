<?php
/**
 * @license see LICENSE
 */

namespace Serps\SearchEngine\Google\Parser\Evaluated\Rule\Natural\Classical;

use Serps\Core\Dom\DomElement;
use Serps\Core\Media\MediaFactory;
use Serps\SearchEngine\Google\Page\GoogleDom;
use Serps\Core\Serp\BaseResult;
use Serps\Core\Serp\IndexedResultSet;
use Serps\SearchEngine\Google\Parser\ParsingRuleInterface;
use Serps\SearchEngine\Google\NaturalResultType;

class ClassicalResult implements ParsingRuleInterface
{

    protected $divClass = 'rc';

    public function match(GoogleDom $dom, \Serps\Core\Dom\DomElement $node)
    {
        if ($node->hasClasses(['g']) && !$node->hasClasses(['mnr-c', 'g-blk'])) {
            // old structure where g > div.rc exists
            if ($dom->cssQuery('.' . $this->divClass, $node)->length == 1) {
                return self::RULE_MATCH_MATCHED;
            }

            // if no match above check if a heading tag exists with 'Web results' as the text
            $heading = $dom->cssQuery('h2', $node);

            if ($heading->length && $heading->item(0)->textContent == 'Web results') {
                // lets try to make this learn how to find the elements
                $this->learnNewElementClass($node);

                return self::RULE_MATCH_MATCHED;
            }
        }

        return self::RULE_MATCH_NOMATCH;
    }

    protected function learnNewElementClass($node)
    {
        foreach ($node->childNodes as $child) {
            $childClass = $child->getAttribute('class');

            if ($child->nodeName == 'div') {
                if ($childClass) {
                    $this->divClass = $child->getAttribute('class');
                    return;
                } else {
                    // if there is no class we should try the childNode
                    return $this->learnNewElementClass($child);
                }
            }
        }
    }

    protected function parseNode(GoogleDom $dom, \DomElement $node)
    {

        // find the title/url
        /* @var $aTag \DOMElement */
        $aTag = $dom
            ->cssQuery('a', $node)
            ->getNodeAt(0);

        if (!$aTag) {
            return;
        }

        $titleTag = $dom
            ->cssQuery('.r h3', $node)
            ->getNodeAt(0);

        if (!$titleTag) {
            $titleTag = $aTag;
        }

        /* @var $h3Tag \DOMElement */
        $h3Tag = $dom
            ->xpathQuery('descendant::h3', $node)
            ->item(0);
        if (!$h3Tag) {
            throw new InvalidDOMException('Cannot parse a classical result.');
        }

        $destinationTag = $dom
            ->cssQuery('div.f cite, div.TbwUpd cite', $node)
            ->getNodeAt(0);

        if (is_a($destinationTag, Serps\Core\Dom\NullDomNode::class)) {
            throw new InvalidDOMException('Cannot parse a classical result.');
        }

        $descriptionTag = $dom
            ->xpathQuery("descendant::span[@class='st']", $node)
            ->item(0);

        if (!$descriptionTag) {
            $descriptionTag = $dom
                ->cssQuery('.' . $this->divClass . ' > div:nth-child(2) span', $node)
                ->getNodeAt(0);
        }

        return [
            'title'   => $h3Tag->nodeValue,
            'url'     => $dom->getUrl()->resolveAsString($aTag->getAttribute('href')),
            'destination' => $destinationTag ? $destinationTag->nodeValue : null,
            // trim needed for mobile results coming with an initial space
            'description' => $descriptionTag ? trim($descriptionTag->nodeValue) : null,
            'isAmp' => function () use ($dom, $node) {
                return $dom
                    ->cssQuery('.amp_r', $node)
                    ->length > 0;
            },
        ];
    }

    /**
     * If isLarge() matched, this will parse the content of site links
     * @param GoogleDom $dom
     * @param \DomElement $node
     * @return \Closure
     */
    protected function parseSiteLink(GoogleDom $dom, \DomElement $node)
    {
        return function () use ($dom, $node) {
            $items = $dom->cssQuery('.mslg .sld', $node);
            $siteLinksData = [];
            foreach ($items as $item) {
                $siteLinksData[] = new BaseResult(NaturalResultType::CLASSICAL_SITELINK, [
                    'title' => function () use ($dom, $item) {
                        return $dom->cssQuery('h3.r a', $item)
                            ->getNodeAt(0)
                            ->getNodeValue();
                    },
                    'description' => function () use ($dom, $item) {
                        return $dom->cssQuery('.st', $item)
                            ->getNodeAt(0)
                            ->getNodeValue();
                    },
                    'url' => function () use ($dom, $item) {
                        return $dom->cssQuery('h3.r a', $item)
                            ->getNodeAt(0)
                            ->getAttribute('href');
                    },
                ]);
            }
            return $siteLinksData;
        };
    }

    /**
     * Check if has site links. Might be overriden by subparser like ClassicalCard
     * @param GoogleDom $dom
     * @param \DomElement $node
     * @return bool
     */
    protected function isLarge(GoogleDom $dom, \DomElement $node)
    {
        return $dom->cssQuery('.nrgt', $node)->length == 1;
    }

    public function parse(GoogleDom $dom, \DomElement $node, IndexedResultSet $resultSet)
    {
        $data = $this->parseNode($dom, $node);

        $resultTypes = [NaturalResultType::CLASSICAL];


        // CLASSICAL RESULT MIGHT BE ENLARGED WITH SITELINKS
        if ($this->isLarge($dom, $node)) {
            $data['sitelinks'] = $this->parseSiteLink($dom, $node);
            $resultTypes[] = NaturalResultType::CLASSICAL_LARGE;
        }


        // classical result can have a video thumbnail
        $thumb = $dom->getXpath()
            ->query("descendant::g-img[@class='_ygd']/img", $node)
            ->item(0);

        if ($thumb) {
            $resultTypes[] = NaturalResultType::CLASSICAL_ILLUSTRATED;

            $data['thumb'] = function () use ($thumb) {
                if ($thumb) {
                    return MediaFactory::createMediaFromSrc($thumb->getAttribute('src'));
                } else {
                    return null;
                }
            };
        }

        $videoDuration = $dom->cssQuery('.vdur', $node);
        if ($videoDuration->length == 1) {
            $resultTypes[] = NaturalResultType::CLASSICAL_VIDEO;
            $data['videoLarge'] = false;
        }


        $item = new BaseResult($resultTypes, $data);
        $resultSet->addItem($item);
    }
}
