<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\app\elements\db;

use Craft;
use craft\app\base\ElementInterface;
use craft\app\base\Field;
use craft\app\db\Query;
use craft\app\helpers\ArrayHelper;
use craft\app\helpers\Db;

/**
 * Parses a relatedTo param on an ElementQuery.
 *
 * @param boolean $isRelationFieldQuery Whether the relatedTo value appears to be for selecting the targets of a single relation field
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class ElementRelationParamParser
{
    // Properties
    // =========================================================================

    /**
     * @var int
     */
    private $_joinSourceMatrixBlocksCount;

    /**
     * @var int
     */
    private $_joinTargetMatrixBlocksCount;

    /**
     * @var int
     */
    private $_joinSourcesCount;

    /**
     * @var int
     */
    private $_joinTargetsCount;

    /**
     * @var int
     */
    private $_sourceLocaleParamCount;

    // Public Methods
    // =========================================================================

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_joinSourceMatrixBlocksCount = 0;
        $this->_joinTargetMatrixBlocksCount = 0;
        $this->_joinSourcesCount = 0;
        $this->_joinTargetsCount = 0;
        $this->_sourceLocaleParamCount = 0;
    }

    /**
     * Parses a relatedTo criteria param and returns the condition(s) or 'false' if there's an issue.
     *
     * @param mixed $relatedTo
     * @param Query $query
     *
     * @return mixed
     */
    public function parseRelationParam($relatedTo, Query $query)
    {
        // Ensure the criteria is an array
        if (is_string($relatedTo)) {
            $relatedTo = ArrayHelper::toArray($relatedTo);
        } else if (!is_array($relatedTo)) {
            $relatedTo = [$relatedTo];
        }

        if (isset($relatedTo['element']) || isset($relatedTo['sourceElement']) || isset($relatedTo['targetElement'])) {
            $relatedTo = [$relatedTo];
        }

        $conditions = [];

        if ($relatedTo[0] == 'and' || $relatedTo[0] == 'or') {
            $glue = array_shift($relatedTo);
        } else {
            $glue = 'or';
        }

        if ($glue == 'or') {
            // Group all of the unspecified elements, so we avoid adding massive JOINs to the query
            $unspecifiedElements = [];

            foreach ($relatedTo as $i => $relCriteria) {
                if (!is_array($relCriteria)) {
                    $unspecifiedElements[] = $relCriteria;
                    unset($relatedTo[$i]);
                }
            }

            if ($unspecifiedElements) {
                $relatedTo[] = ['element' => $unspecifiedElements];
            }
        }

        foreach ($relatedTo as $relCriteria) {
            $condition = $this->_subparseRelationParam($relCriteria, $query);

            if ($condition) {
                $conditions[] = $condition;
            } else if ($glue == 'or') {
                continue;
            } else {
                return false;
            }
        }

        if ($conditions) {
            if (count($conditions) == 1) {
                return $conditions[0];
            }

            array_unshift($conditions, $glue);

            return $conditions;
        }

        return false;
    }

    /**
     * Returns whether the relatedTo value appears to be for selecting the targets of a single relation field.
     *
     * @return boolean
     */
    public function getIsRelationFieldQuery()
    {
        return (
            $this->_joinSourcesCount == 1 &&
            !$this->_joinTargetsCount &&
            !$this->_joinSourceMatrixBlocksCount &&
            !$this->_joinTargetMatrixBlocksCount
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Parses a part of a relatedTo criteria param and returns the condition or 'false' if there's an issue.
     *
     * @param mixed $relCriteria
     * @param Query $query
     *
     * @return mixed
     */
    private function _subparseRelationParam($relCriteria, Query $query)
    {
        if (!is_array($relCriteria)) {
            $relCriteria = ['element' => $relCriteria];
        }

        // Get the element IDs, wherever they are
        $relElementIds = [];
        $glue = 'or';

        $elementParams = ['element', 'sourceElement', 'targetElement'];
        $elementParam = null;

        foreach ($elementParams as $elementParam) {
            if (isset($relCriteria[$elementParam])) {
                $elements = ArrayHelper::toArray($relCriteria[$elementParam]);

                if (isset($elements[0]) && ($elements[0] == 'and' || $elements[0] == 'or')) {
                    $glue = array_shift($elements);
                }

                foreach ($elements as $element) {
                    if (is_numeric($element)) {
                        $relElementIds[] = $element;
                    } else if ($element instanceof ElementInterface) {
                        $relElementIds[] = $element->id;
                    } else if ($element instanceof ElementQueryInterface) {
                        $relElementIds = array_merge($relElementIds, $element->ids());
                    }
                }

                break;
            }
        }

        if (!$relElementIds) {
            return false;
        }

        // Going both ways?
        if (isset($relCriteria['element'])) {
            if (!isset($relCriteria['field'])) {
                $relCriteria['field'] = null;
            }

            array_unshift($relElementIds, $glue);

            return $this->parseRelationParam([
                'or',
                [
                    'sourceElement' => $relElementIds,
                    'field' => $relCriteria['field']
                ],
                [
                    'targetElement' => $relElementIds,
                    'field' => $relCriteria['field']
                ]
            ], $query);
        } // Do we need to check for *all* of the element IDs?
        else if ($glue == 'and') {
            // Srpead it across multiple relation sub-params
            $newRelatedToParam = ['and'];

            foreach ($relElementIds as $elementId) {
                $newRelatedToParam[] = [$elementParam => [$elementId]];
            }

            return $this->parseRelationParam($newRelatedToParam, $query);
        }

        $conditions = [];
        $normalFieldIds = [];

        if (!empty($relCriteria['field'])) {
            // Loop through all of the fields in this rel criteria, create the Matrix-specific conditions right away
            // and save the normal field IDs for later
            $fields = ArrayHelper::toArray($relCriteria['field']);

            foreach ($fields as $field) {
                $fieldModel = null;

                if (is_numeric($field)) {
                    $fieldHandleParts = null;
                    $fieldModel = Craft::$app->getFields()->getFieldById($field);
                } else {
                    $fieldHandleParts = explode('.', $field);
                    $fieldModel = Craft::$app->getFields()->getFieldByHandle($fieldHandleParts[0]);
                }

                if (!$fieldModel) {
                    continue;
                }

                // Is this a Matrix field?
                if ($fieldModel->type == 'Matrix') {
                    $blockTypeFieldIds = [];

                    // Searching by a specific block type field?
                    if (isset($fieldHandleParts[1])) {
                        // There could be more than one block type field with this handle, so we must loop through all
                        // of the block types on this Matrix field
                        $blockTypes = Craft::$app->getMatrix()->getBlockTypesByFieldId($fieldModel->id);

                        foreach ($blockTypes as $blockType) {
                            foreach ($blockType->getFields() as $blockTypeField) {
                                /** @var Field $blockTypeField */
                                if ($blockTypeField->handle == $fieldHandleParts[1]) {
                                    $blockTypeFieldIds[] = $blockTypeField->id;
                                    break;
                                }
                            }
                        }

                        if (!$blockTypeFieldIds) {
                            continue;
                        }
                    }

                    if (isset($relCriteria['sourceElement'])) {
                        $this->_joinSourcesCount++;
                        $this->_joinTargetMatrixBlocksCount++;

                        $sourcesAlias = 'sources'.$this->_joinSourcesCount;
                        $targetMatrixBlocksAlias = 'target_matrixblocks'.$this->_joinTargetMatrixBlocksCount;

                        $relationsJoinConditions = [
                            'and',
                            $sourcesAlias.'.targetId = elements.id'
                        ];
                        $relationsJoinParams = [];

                        if (!empty($relCriteria['sourceLocale'])) {
                            $this->_sourceLocaleParamCount++;
                            $sourceLocaleParam = ':sourceLocale'.$this->_sourceLocaleParamCount;

                            $relationsJoinConditions[] = [
                                'or',
                                $sourcesAlias.'.sourceLocale is null',
                                $sourcesAlias.'.sourceLocale = '.$sourceLocaleParam
                            ];
                            $relationsJoinParams[$sourceLocaleParam] = $relCriteria['sourceLocale'];
                        }

                        $query->leftJoin('{{%relations}} '.$sourcesAlias, $relationsJoinConditions, $relationsJoinParams);
                        $query->leftJoin('{{%matrixblocks}} '.$targetMatrixBlocksAlias, $targetMatrixBlocksAlias.'.id = '.$sourcesAlias.'.sourceId');

                        $condition = [
                            'and',
                            Db::parseParam($targetMatrixBlocksAlias.'.ownerId', $relElementIds, $query->params),
                            $targetMatrixBlocksAlias.'.fieldId = '.$fieldModel->id
                        ];

                        if ($blockTypeFieldIds) {
                            $condition[] = Db::parseParam($sourcesAlias.'.fieldId', $blockTypeFieldIds, $query->params);
                        }
                    } else {
                        $this->_joinSourceMatrixBlocksCount++;
                        $sourceMatrixBlocksAlias = 'source_matrixblocks'.$this->_joinSourceMatrixBlocksCount;
                        $matrixBlockTargetsAlias = 'matrixblock_targets'.$this->_joinSourceMatrixBlocksCount;

                        $relationsJoinConditions = [
                            'and',
                            $matrixBlockTargetsAlias.'.sourceId = '.$sourceMatrixBlocksAlias.'.id'
                        ];
                        $relationsJoinParams = [];

                        if (!empty($relCriteria['sourceLocale'])) {
                            $this->_sourceLocaleParamCount++;
                            $sourceLocaleParam = ':sourceLocale'.$this->_sourceLocaleParamCount;

                            $relationsJoinConditions[] = [
                                'or',
                                $matrixBlockTargetsAlias.'.sourceLocale is null',
                                $matrixBlockTargetsAlias.'.sourceLocale = '.$sourceLocaleParam
                            ];
                            $relationsJoinParams[$sourceLocaleParam] = $relCriteria['sourceLocale'];
                        }

                        $query->leftJoin('{{%matrixblocks}} '.$sourceMatrixBlocksAlias, $sourceMatrixBlocksAlias.'.ownerId = elements.id');
                        $query->leftJoin('{{%relations}} '.$matrixBlockTargetsAlias, $relationsJoinConditions, $relationsJoinParams);

                        $condition = [
                            'and',
                            Db::parseParam($matrixBlockTargetsAlias.'.targetId', $relElementIds, $query->params),
                            $sourceMatrixBlocksAlias.'.fieldId = '.$fieldModel->id
                        ];

                        if ($blockTypeFieldIds) {
                            $condition[] = Db::parseParam($matrixBlockTargetsAlias.'.fieldId', $blockTypeFieldIds, $query->params);
                        }
                    }

                    $conditions[] = $condition;
                } else {
                    $normalFieldIds[] = $fieldModel->id;
                }
            }
        }

        // If there were no fields, or there are some non-Matrix fields, add the normal relation condition. (Basically,
        // run this code if the rel criteria wasn't exclusively for Matrix.)
        if (empty($relCriteria['field']) || $normalFieldIds) {
            if (isset($relCriteria['sourceElement'])) {
                $this->_joinSourcesCount++;
                $relTableAlias = 'sources'.$this->_joinSourcesCount;
                $relConditionColumn = 'sourceId';
                $relElementColumn = 'targetId';
            } else {
                // $relCriteria['targetElement'], then
                $this->_joinTargetsCount++;
                $relTableAlias = 'targets'.$this->_joinTargetsCount;
                $relConditionColumn = 'targetId';
                $relElementColumn = 'sourceId';
            }

            $relationsJoinConditions = [
                'and',
                $relTableAlias.'.'.$relElementColumn.' = elements.id'
            ];
            $relationsJoinParams = [];

            if (!empty($relCriteria['sourceLocale'])) {
                $this->_sourceLocaleParamCount++;
                $sourceLocaleParam = ':sourceLocale'.$this->_sourceLocaleParamCount;

                $relationsJoinConditions[] = [
                    'or',
                    $relTableAlias.'.sourceLocale is null',
                    $relTableAlias.'.sourceLocale = '.$sourceLocaleParam
                ];
                $relationsJoinParams[$sourceLocaleParam] = $relCriteria['sourceLocale'];
            }

            $query->leftJoin('{{%relations}} '.$relTableAlias, $relationsJoinConditions, $relationsJoinParams);
            $condition = Db::parseParam($relTableAlias.'.'.$relConditionColumn, $relElementIds, $query->params);

            if ($normalFieldIds) {
                $condition = [
                    'and',
                    $condition,
                    Db::parseParam($relTableAlias.'.fieldId', $normalFieldIds, $query->params)
                ];
            }

            $conditions[] = $condition;
        }

        if ($conditions) {
            if (count($conditions) == 1) {
                return $conditions[0];
            }

            array_unshift($conditions, 'or');

            return $conditions;
        }

        return false;
    }
}
