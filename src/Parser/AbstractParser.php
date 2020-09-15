<?php
/**
 * @license see LICENSE
 */

namespace Serps\SearchEngine\Google\Parser;

use Serps\Core\Dom\DomNodeList;
use Serps\Core\Serp\IndexedResultSet;
use Serps\SearchEngine\Google\Page\GoogleDom;

abstract class AbstractParser
{

    /**
     * @var ParsingRuleInterface[]
     */
    protected $rules = null;

    /**
     * @return ParsingRuleInterface[]
     */
    abstract protected function generateRules();

    /**
     * @param GoogleDom $googleDom
     * @return DomNodeList
     */
    abstract protected function getParsableItems(GoogleDom $googleDom);


    /**
     * @return ParsingRuleInterface[]
     */
    public function getRules()
    {
        if (null == $this->rules) {
            $this->rules = $this->generateRules();
        }
        return $this->rules;
    }

    /**
     * Parses the given google dom
     * @param GoogleDom $googleDom
     * @return IndexedResultSet
     */
    public function parse(GoogleDom $googleDom)
    {
        $elementGroups = $this->getParsableItems($googleDom);
        $resultSet = $this->createResultSet($googleDom);
        return $this->parseGroups($elementGroups, $resultSet, $googleDom);
    }

    protected function createResultSet(GoogleDom $googleDom)
    {
        $startingAt = (int) $googleDom->getUrl()->getParamValue('start', 0);
        return new IndexedResultSet($startingAt + 1);
    }

    /**
     * @param $elementGroups
     * @param IndexedResultSet $resultSet
     * @param $googleDom
     * @return IndexedResultSet
     */
    protected function parseGroups(DomNodeList $elementGroups, IndexedResultSet $resultSet, $googleDom)
    {
        $rules = $this->getRules();

        foreach ($elementGroups as $group) {
            $matched = false;

            if (!($group instanceof \DOMElement)) {
                continue;
            }
            foreach ($rules as $rule) {
                $match = $rule->match($googleDom, $group);
                if ($match instanceof \DOMNodeList) {
                    $this->parseGroups(new DomNodeList($match, $googleDom), $resultSet, $googleDom);
                    break;

                } elseif ($match instanceof DomNodeList) {
                    $this->parseGroups($match, $resultSet, $googleDom);
                    break;

                } else {
                    switch ($match) {
                        case ParsingRuleInterface::RULE_MATCH_MATCHED:
                            $matched = true;
                            $rule->parse($googleDom, $group, $resultSet);
                            break 2;
                        case ParsingRuleInterface::RULE_MATCH_STOP:
                            break 2;
                    }
                }
            }

            // 9-15-20: google has changed their dom to include a wrapping div
            // with no easily identifiable class or id, so lets try to feel our
            // way through the dom
            if (!$matched) {
                $this->parseGroups($group->getChildren(), $resultSet, $googleDom);
            }
        }
        return $resultSet;
    }
}
