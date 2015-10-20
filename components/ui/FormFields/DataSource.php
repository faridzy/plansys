<?php

/**
 * Class DataSource
 * @author rizky
 */
class DataSource extends FormField {

    public static $toolbarName       = "Data Source";
    public static $category          = "Data & Tables";
    public static $toolbarIcon       = "glyphicon glyphicon-book";
    public        $name              = '';
    public        $fieldType         = 'sql';
    public        $sql               = '';
    public        $php               = '';
    public        $postData          = 'Yes';
    public        $params            = [];
    public        $data;
    public        $debugSql          = 'No';
    public        $enablePaging      = 'No';
    public        $pagingSQL         = '';
    public        $pagingPHP         = '';
    public        $relationTo        = '';
    public        $queryParams       = [];
    public        $relationCriteria  = [
        'select' => '',
        'distinct' => 'false',
        'alias' => 't',
        'condition' => '{[where]}',
        'order' => '{[order]}',
        'paging' => '{[paging]}',
        'group' => '',
        'having' => '',
        'join' => ''
    ];
    public        $aggregateGroups   = [];
    public        $aggregateColumns  = [];
    public        $maxAggregateLevel = 99;
    private       $postedParams      = [];
    private       $lastCount         = 0;
    private       $command;

    public static function querySql($sql, $params, $form = '', $dsname = '') {
        $ds = new DataSource;

        if ($form != '') {
            $fb          = FormBuilder::load($form);
            $ds->builder = $fb;

            if ($dsname != '') {
                $field      = $fb->findField(['name' => $dsname]);
                $ds->params = $field['params'];
            }
        }

        $ds->sql = $sql;
        return $ds->query($params);
    }

    /**
     * @param string $sql parameter query yang akan di-execute
     * @return mixed me-return array kosong jika parameter $sql == "", jika tidak maka akan me-return array data hasil execute SQL
     */
    public function query($params = []) {
        $paramDefs = $params;
        $params    = array_merge($params, $this->queryParams);
        if (trim($this->sql) == "")
            return [];

        $db       = Yii::app()->db;
        $template = DataSource::generateTemplate($this->sql, $params, $this, $paramDefs);

        ## execute SQL
        $this->command = $db->createCommand($template['sql']);
        $data          = $this->command->queryAll(true, $template['params']);

        ## if should count, then count..
        if ($this->lastCount == 0) {
            if ($this->enablePaging == 'Yes') {
                $tc    = DataSource::generateTemplate($this->pagingSQL, $params, $this);
                $count = $db->createCommand($tc['sql'])->queryAll(true, $tc['params']);

                if (count($count) > 0) {
                    $count = array_values($count[0]);
                    $count = $count[0];
                } else {
                    $count = 0;
                }
                $template['countSQL'] = $tc['sql'];
            } else {
                $count = count($data);
            }
        } else {
            $count = $this->lastCount;

            ## default shouldcount to true;
            $this->lastCount = 0;
        }

        $template['count']     = $count;
        $template['timestamp'] = date('Y-m-d H:i:s');

        $template['sql'] = SqlFormatter::format($template['sql']);

        ## return data
        return [
            'data' => $data,
            'count' => $count,
            'debug' => $template,
        ];
    }

    public static function generateTemplate($sql, $postedParams = [], $field, $paramDefs = []) {
        $returnParams = [];

        ## find all params
        preg_match_all("/\:[\w\d_]+/", $sql, $params);
        $model = $field->model;
        foreach ($params[0] as $idx => $p) {
            if (isset($postedParams[$p])) {
                if (is_string($postedParams[$p])) {
                    $isJs = strpos($postedParams[$p], 'js:') !== false || (isset($paramDefs[$p]) && strpos($paramDefs[$p], 'js:') !== false);

                    if ($isJs) {
                        switch (get_class($field)) {
                            case "DataSource":
                                $returnParams[$p] = @$field->queryParams[$p];
                                break;
                            default:
                                $returnParams[$p] = '';
                                break;
                        }
                    } else {
                        $postParam = $postedParams[$p];
                        if (stripos($postParam, 'php:') === 0) {
                            $postParam = substr($postParam, 4);
                        }
                        if (!!$postParam) {
                            $returnParams[$p] = $field->evaluate($postParam, true, [
                                'model' => $model
                            ]);
                        }
                    }
                } else if (is_array($postedParams[$p]) && !empty($postedParams[$p])) {
                    $returnParams[$p] = $postedParams[$p];
                    $params[0][$idx]  = [
                        'name' => $p,
                        'length' => count($postedParams[$p])
                    ];
                }
            }
        }

        ## find all blocks
        preg_match_all("/\{(.*?)\}/", $sql, $blocks);
        foreach ($blocks[1] as $block) {
            if (strtolower($block) == "and" || strtolower($block) == "or") {
                continue;
            }

            $bracket = DataSource::processSQLBracket($block, $postedParams, $field);

            $renderBracket = false;
            if (isset($bracket['render'])) {
                $renderBracket = $bracket['render'];
            }

            foreach ($bracket['params'] as $bracketParam => $bracketValue) {
                if (is_array($bracketValue) && count($bracketValue) > 0) {
                    $renderBracket = true;
                    foreach ($bracketValue as $k => $p) {
                        $returnParams[$k] = $p;
                    }
                }
            }

            ## check if there is another params
            preg_match_all("/\:[\w\d_]+/", $bracket['sql'], $attachedParams);

            if (count($attachedParams[0]) > 0) {
                $inParams = 0;
                foreach ($attachedParams[0] as $ap) {
                    if (isset($returnParams[$ap])) {
                        $inParams++;

                        ## if current params is an ARRAY then convert to multiple params
                        if (is_array($returnParams[$ap]) && !empty($returnParams[$ap])) {
                            $newParamString = [];
                            foreach ($returnParams[$ap] as $rpIdx => $rp) {
                                $rpKey                = $ap . "_" . $rpIdx;
                                $newParamString[]     = $rpKey;
                                $returnParams[$rpKey] = $rp;
                            }
                            unset($returnParams[$ap]);
                            $bracket['sql'] = Helper::strReplaceFirst($ap, implode(",", $newParamString), $bracket['sql']);
                        }
                    }
                }

                if ($inParams == count($attachedParams)) {
                    $renderBracket = true;
                }
            }

            if ($renderBracket) {
                $sql = str_replace("{{$block}}", $bracket['sql'], $sql);
            } else {
                $sql = str_replace("{{$block}}", "", $sql);
            }
        }

        ## concat 'WHERE' sql with operators
        if ($sql != "") {
            $sql = DataSource::concatSql($sql, "AND");
            $sql = DataSource::concatSql($sql, "OR");
        }

        ## remove uneeded return params
        preg_match_all("/\:[\w\d_]+/", $sql, $cp);
        foreach ($returnParams as $k => $p) {
            if (!in_array($k, $cp[0]) && !in_array(':' . $k, $cp[0])) {
                unset($returnParams[$k]);
            }
        }

        return [
            'sql' => trim($sql),
            'params' => $returnParams
        ];
    }

    // HANYA MEMPROSES SQL BRACKET []!! TIDAK MEMPROSES PARAMETERS
    protected static function processSQLBracket($sql, $postedParams, $field) {
        preg_match_all("/\[(.*?)\]/", $sql, $matches);
        $params = $matches[1];
        $parsed = [];

        foreach ($params as $param) {
            $template     = $sql;
            $paramOptions = explode("|", $param);
            $param        = array_shift($paramOptions);

            if (($param != "order" && $param != "!order") && (!isset($field->params[$param]) && !isset($field->queryParams[$param]))) {
                $sql            = str_replace("[{$param}]", "", $sql);
                $parsed[$param] = "";
                continue;
            }

            switch ($param) {
                case "where":
                    $fieldSql = 'DataFilter::generateParams($paramName, $params, $template, $paramOptions)';
                    break;
                case "order":
                case "!order":
                case "paging":
                    if ($param == "!order") {
                        $param = "order";
                    }

                    $fieldSql = 'DataGrid::generateParams($paramName, $params, $template, $paramOptions)';
                    break;
                default:
                    $ff       = $field->builder->findField(['name' => $field->params[$param]]);
                    $fieldSql = @$ff['options']['ps-ds-sql'];
                    break;
            }


            if (isset($fieldSql)) {
                $template = $field->evaluate($fieldSql, true, [
                    'paramName' => $param,
                    'params' => @$postedParams[$param],
                    'template' => $template,
                    'paramOptions' => $paramOptions
                ]);

                if (!isset($template['generateTemplate'])) {
                    $sql = str_replace("[{$param}]", $template['sql'], $sql);
                } else {
                    $sql = $template['sql'];
                }

                if ($template['sql'] != '') {
                    $parsed[$param] = $template['params'];
                }

                if (isset($template['render'])) {
                    return ['sql' => $sql, 'params' => $parsed, 'render' => $template['render']];
                }
            }
        }
        return ['sql' => $sql, 'params' => $parsed];
    }

    public static function concatSql($sql, $operator) {
        $andsql = array_filter(preg_split("/\{" . $operator . "\}/i", $sql), function ($e) {
            return (trim($e) != "" ? trim($e) : false);
        });

        $sql = implode(" " . $operator . " ", $andsql);

        ## clean where where
        $sql = preg_replace("/\s+{$operator}\s+where\s+/i", " " . $operator . " ", $sql);
        $sql = preg_replace("/\s+where\s+{$operator}\s+/i", " WHERE ", $sql);
        $sql = preg_replace("/\s+where\s+where\s+/i", " WHERE ", $sql);

        ## clean ( AND
        $sql = preg_replace("/\s*\(\s+{$operator}\s+/i", " ( ", $sql);
        $sql = preg_replace("/\s+{$operator}\s+\)\s+/i", " ) ", $sql);
        return $sql;
    }

    /**
     * @return array Fungsi ini akan me-return array property DataSource.
     */
    public function getFieldProperties() {
        return array(
            array(
                'label' => 'Data Source Name',
                'name' => 'name',
                'labelWidth' => '5',
                'fieldWidth' => '7',
                'options' => array(
                    'ng-model' => 'active.name',
                    'ng-change' => 'changeActiveName()',
                    'ng-delay' => '500',
                ),
                'type' => 'TextField',
            ),
            array(
                'label' => 'Post Data ?',
                'name' => 'postData',
                'options' => array(
                    'ng-model' => 'active.postData',
                    'ng-change' => 'save()',
                ),
                'listExpr' => 'array(\'Yes\',\'No\')',
                'labelWidth' => '5',
                'fieldWidth' => '4',
                'type' => 'DropDownList',
            ),
            array(
                'label' => 'Relation To',
                'name' => 'relationTo',
                'options' => array(
                    'ng-model' => 'active.relationTo',
                    'ng-change' => 'save()',
                    'ps-list' => 'relFieldList',
                    'ng-if' => 'active.postData == \'Yes\'',
                ),
                'list' => array(),
                'labelWidth' => '5',
                'fieldWidth' => '7',
                'searchable' => 'Yes',
                'otherLabel' => '-- NONE --',
                'type' => 'DropDownList',
            ),
            array(
                'label' => 'Debug SQL ?',
                'name' => 'debugSql',
                'options' => array(
                    'ng-model' => 'active.debugSql',
                    'ng-change' => 'save()',
                ),
                'listExpr' => 'array(\'Yes\',\'No\')',
                'labelWidth' => '5',
                'fieldWidth' => '4',
                'type' => 'DropDownList',
            ),
            array(
                'label' => 'Source Type',
                'name' => 'fieldType',
                'options' => array(
                    'ng-model' => 'active.fieldType',
                    'ng-change' => 'save()',
                    'ng-if' => 'active.relationTo == \'\' || active.postData == \'No\'',
                ),
                'list' => array(
                    'sql' => 'SQL',
                    'phpsql' => 'PHP (Return SQL)',
                    'php' => 'PHP (Return Array)',
                ),
                'labelWidth' => '5',
                'fieldWidth' => '6',
                'type' => 'DropDownList',
            ),
            array(
                'label' => 'Paging',
                'name' => 'enablePaging',
                'options' => array(
                    'ng-model' => 'active.enablePaging',
                    'ng-change' => 'save()',
                    'ng-if' => 'active.relationTo == \'\' || active.postData == \'No\'',
                ),
                'listExpr' => 'array(\'Yes\',\'No\')',
                'labelWidth' => '5',
                'fieldWidth' => '4',
                'type' => 'DropDownList',
            ),
            array(
                'name' => 'relationCriteria',
                'label' => 'Relation Query',
                'paramsField' => 'params',
                'baseClass' => 'DataSource',
                'options' => array(
                    'ng-if' => 'active.postData == \'Yes\' && active.relationTo != \'\'',
                    'ng-model' => 'active.relationCriteria',
                    'ng-change' => 'save()',
                ),
                'modelClassJS' => 'DataSource/relation-criteria.js',
                'type' => 'SqlCriteria',
            ),
            array(
                'label' => 'SQL',
                'fieldname' => 'sql',
                'language' => 'sql',
                'options' => array(
                    'ng-show' => 'active.fieldType == \'sql\' && (active.relationTo == \'\' || active.postData == \'No\')',
                    'ps-valid' => 'save();',
                ),
                'type' => 'ExpressionField',
            ),
            array(
                'label' => 'PHP Function',
                'fieldname' => 'php',
                'options' => array(
                    'ng-show' => '(active.fieldType == \'php\' || active.fieldType == \'phpsql\') && (active.relationTo == \'\' || active.postData == \'No\')',
                    'ps-valid' => 'save();',
                ),
                'desc' => 'ex: Model::yourFunction($params);',
                'type' => 'ExpressionField',
            ),
            array(
                'label' => 'Total Item - PHP Function',
                'fieldname' => 'pagingPHP',
                'options' => array(
                    'ng-show' => '(active.fieldType == \'php\' || active.fieldType == \'phpsql\') && active.enablePaging == \'Yes\' && (active.relationTo == \'\' || active.postData == \'No\')',
                    'ps-valid' => 'save();',
                ),
                'desc' => 'ex: Model::yourFunction($params);',
                'type' => 'ExpressionField',
            ),
            array(
                'label' => 'Total Item - SQL',
                'fieldname' => 'pagingSQL',
                'language' => 'sql',
                'options' => array(
                    'ng-show' => 'active.fieldType == \'sql\' && active.enablePaging == \'Yes\' && (active.relationTo == \'\' || active.postData == \'No\')',
                    'ps-valid' => 'save();',
                ),
                'type' => 'ExpressionField',
            ),
            array(
                'label' => 'Parameters',
                'name' => 'params',
                'show' => 'Show',
                'type' => 'KeyValueGrid',
            ),
            array(
                'type' => 'Text',
                'value' => '<div ng-if=\'active.postData == \"Yes\" && !!active.relationTo\'>
    
    <div ng-init=\"active.$showGrouping = active.aggregateGroups.length == 0\"></div>',
            ),
            array(
                'title' => 'Grouping',
                'type' => 'SectionHeader',
            ),
            array(
                'label' => 'Hide',
                'icon' => 'chevron-up',
                'position' => 'right',
                'buttonSize' => 'btn-xs',
                'options' => array(
                    'ng-click' => 'active.$showGrouping = !active.$showGrouping',
                    'style' => 'margin-top:-25px;',
                    'ng-if' => 'active.$showGrouping',
                ),
                'type' => 'LinkButton',
            ),
            array(
                'label' => 'Edit',
                'icon' => 'chevron-down',
                'position' => 'right',
                'buttonSize' => 'btn-xs',
                'options' => array(
                    'ng-click' => 'active.$showGrouping = !active.$showGrouping',
                    'style' => 'margin-top:-25px;',
                    'ng-if' => '!active.$showGrouping',
                ),
                'type' => 'LinkButton',
            ),
            array(
                'type' => 'Text',
                'value' => '<div ng-if=\\"active.$showGrouping\\" style=\\"margin-top:5px\\">',
            ),
            array(
                'label' => 'Max. Aggregate Level',
                'name' => 'maxAggregateLevel',
                'labelWidth' => '7',
                'fieldWidth' => '5',
                'postfix' => 'level',
                'options' => array(
                    'ng-model' => 'active.maxAggregateLevel',
                    'ng-delay' => '500',
                    'ng-change' => 'save();',
                    'ng-if' => 'false',
                ),
                'labelOptions' => array(
                    'style' => 'text-align:left;',
                ),
                'type' => 'TextField',
            ),
            array(
                'name' => 'aggregateGroups',
                'fieldTemplate' => 'form',
                'templateForm' => 'application.components.ui.FormFields.DataSourceAggregateGroup',
                'labelWidth' => '0',
                'fieldWidth' => '12',
                'options' => array(
                    'ng-model' => 'active.aggregateGroups',
                    'ng-change' => 'save()',
                ),
                'sortable' => 'No',
                'singleViewOption' => array(
                    'name' => 'val',
                    'fieldType' => 'text',
                    'labelWidth' => 0,
                    'fieldWidth' => 12,
                    'fieldOptions' => array(
                        'ng-delay' => 500,
                    ),
                ),
                'type' => 'ListView',
            ),
            array(
                'type' => 'Text',
                'value' => '</div>
<div style=\"height:5px\"></div>
<div ng-if=\"active.aggregateGroups.length > 0\">',
            ),
            array(
                'title' => 'Aggregates',
                'type' => 'SectionHeader',
            ),
            array(
                'label' => 'Edit',
                'icon' => 'chevron-down',
                'position' => 'right',
                'buttonSize' => 'btn-xs',
                'options' => array(
                    'ng-click' => 'active.$showAggregate = !active.$showAggregate',
                    'style' => 'margin-top:-25px;',
                    'ng-if' => '!active.$showAggregate',
                ),
                'type' => 'LinkButton',
            ),
            array(
                'label' => 'Hide',
                'icon' => 'chevron-up',
                'position' => 'right',
                'buttonSize' => 'btn-xs',
                'options' => array(
                    'ng-click' => 'active.$showAggregate = !active.$showAggregate',
                    'style' => 'margin-top:-25px;',
                    'ng-if' => 'active.$showAggregate',
                ),
                'type' => 'LinkButton',
            ),
            array(
                'type' => 'Text',
                'value' => '<div ng-if=\\"active.$showAggregate\\" style=\\"margin-top:5px\\">',
            ),
            array(
                'name' => 'aggregateColumns',
                'fieldTemplate' => 'form',
                'templateForm' => 'application.components.ui.FormFields.DataSourceAggregateCol',
                'layout' => 'Vertical',
                'fieldWidth' => '12',
                'options' => array(
                    'ng-model' => 'active.aggregateColumns',
                    'ng-change' => 'save()',
                ),
                'singleViewOption' => array(
                    'name' => 'val',
                    'fieldType' => 'text',
                    'labelWidth' => 0,
                    'fieldWidth' => 12,
                    'fieldOptions' => array(
                        'ng-delay' => 500,
                    ),
                ),
                'type' => 'ListView',
            ),
            array(
                'type' => 'Text',
                'value' => '</div>',
            ),
            array(
                'type' => 'Text',
                'value' => '</div>',
            ),
            array(
                'type' => 'Text',
                'value' => '</div>',
            ),
        );
    }

    public function actionRelClass() {
        Yii::import($_GET['class']);
        $class = Helper::explodeLast(".", $_GET['class']);

        $relClass = '';
        if (@$_GET['rel'] == 'currentModel') {
            $relClass = $class;
        } else {
            $model    = new $class;
            $rels     = $model->relations();
            $relClass = @$rels[$_GET['rel']][1];
        }

        echo Helper::classAlias($relClass);
    }

    public function getPrimaryKey() {
        if ($this->relationTo == '' || $this->postData == 'No') {
            return 'id';
        } else {
            if ($this->relationTo == 'currentModel') {
                return $this->model->metadata->tableSchema->primaryKey;
            } else {
                $rel       = $this->model->metaData->relations[$this->relationTo];
                $className = $rel->className;
                if (class_exists($className, false)) {
                    $pk = $className::model()->metadata->tableSchema->primaryKey;
                    if (is_string($pk)) {
                        return $pk;
                    }
                }
            }
        }
    }

    public function actionQuery() {
        $postdata = file_get_contents("php://input");
        $post     = CJSON::decode($postdata);
        $class    = Helper::explodeLast(".", $post['class']);
        Yii::import($post['class']);
        $this->lastCount = @$post['lc'] > 0 ? @$post['lc'] : 0;

        if (class_exists($class)) {
            $fb    = FormBuilder::load($class);
            $field = $fb->findField(['name' => $post['name']]);
            if ($field['fieldType'] != "php" && method_exists($class, 'model')) {
                $fb->model = $class::model()->findByPk(@$post['model_id']);
                if (is_null($fb->model)) {
                    $fb->model = new $class;
                }
            }

            $this->attributes  = $field;
            $this->builder     = $fb;
            $this->queryParams = (is_array(@$post['params']) ? @$post['params'] : []);

            if (is_object($this->model) && isset($post['modelParams'])) {
                $this->model->attributes = $post['modelParams'];
            }

            $isGenerate = isset($post['generate']);

            if (is_string($this->params)) {
                $this->params = [];
            }


            if ($this->postData == 'No' || $this->relationTo == '' || $this->relationTo == '-- NONE --') {
                ## without relatedTo

                switch ($this->fieldType) {
                    case "sql":
                        $data = $this->query($this->params);
                        break;
                    case "phpsql":
                        $this->sql = $this->execute($this->params);
                        $data      = $this->query($this->params);
                        break;
                    case "php":
                        $data = $this->execute($this->params);
                        break;
                }
            } else {
                ## with relatedTo
                $data = $this->getRelated($this->params, $isGenerate);
            }

            if (empty($data)) {
                echo "{}";
                die();
            }

            echo json_encode([
                'data' => $data['data'],
                'count' => $data['debug']['count'],
                'params' => $data['debug']['params'],
                'debug' => ($this->debugSql == 'Yes' ? $data['debug'] : [])
            ]);
        }
    }

    public function execute($params = []) {
        $params = array_merge($params, $this->queryParams);

        $data = $this->evaluate($this->php, true, [
            'params' => $params,
            'model' => $this->model
        ]);

        $count     = count($data);
        $countFunc = 'count($data);';
        if ($this->enablePaging == 'Yes') {
            if ($this->fieldType == 'phpsql') {
                $this->pagingSQL = $this->evaluate($this->pagingPHP, true, ['params' => $params]);
                $this->pagingSQL = str_replace("{[paging]}", "", $this->pagingSQL);
            } else {
                $count = $this->evaluate($this->pagingPHP, true, ['params' => $params]);
            }
            $countFunc = $this->pagingPHP;
        }

        if ($this->fieldType == "php") {
            return [
                'data' => $data,
                'count' => $count,
                'debug' => [
                    'function' => $this->php,
                    'count' => $count,
                    'countFunction' => $countFunc,
                    'params' => $params,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
        } else {
            return $data;
        }
    }

    public function getRelated($params = [], $isGenerate = false) {
        $postedParams = array_merge($params, $this->queryParams);
        $relChanges   = $this->model->getRelChanges($this->relationTo);
        $criteria     = DataSource::generateCriteria($postedParams, $this->relationCriteria, $this);

        if (@$criteria['params']) {
            $criteria['params'] = array_filter($criteria['params']);
        }

        $criteriaCount = $criteria;
        if ($this->relationTo == 'currentModel') {
            $tableSchema = $this->model->tableSchema;
            $builder     = $this->model->commandBuilder;
            if (array_key_exists('page', $criteriaCount)) {
                $start                   = ($criteriaCount['page'] - 1) * $criteriaCount['pageSize'];
                $pageSize                = $criteriaCount['pageSize'];
                $criteriaCount['limit']  = $pageSize;
                $criteriaCount['offset'] = $start;

                unset($criteriaCount['pageSize']);
                unset($criteriaCount['page']);
            }

            $countCommand = $builder->createCountCommand($tableSchema, new CDbCriteria($criteriaCount));
            $count        = $countCommand->queryScalar();
        } else {
            $criteriaCount           = $criteria;
            $criteriaCount['select'] = 'count(1) as id';
            $rawCount                = $this->model->getRelated($this->relationTo, true, $criteriaCount);

            if (is_object($rawCount)) {
                $count = 1;
            } else if (!is_array($rawCount) && !is_null($rawCount)) {
                throw New Exception('Relation defintion is wrong! check your relations() function in model');
            } else {
                $count = count($rawCount) > 0 ? $rawCount[0]['id'] : 0;
                if (isset($count['id'])) {
                    $count = $count['id'];
                }
            }
        }

        if (!empty($this->aggregateGroups) && !$isGenerate) {
            $criteria['aggregate'] = [
                'groups' => $this->aggregateGroups,
                'columns' => []
            ];

            foreach ($this->aggregateColumns as $k => $c) {
                $criteria['aggregate']['columns'][$c['col']] = [
                    'type' => $c['colType'],
                    'col' => $c['col']
                ];

                if ($c['colType'] == 'custom') {
                    $criteria['aggregate']['columns'][$c['col']]['custom'] = $c['customType'];
                }
            }
        }

        $rawData = $this->model->{$this->relationTo}($criteria, false);

        if (count($rawData) == 0 && $isGenerate) {
            if ($this->relationTo != 'currentModel') {
                $rels     = $this->model->relations();
                $relClass = $rels[$this->relationTo][1];
            } else {
                $relClass = get_class($this->model);
            }

            $rawData = [$relClass::model()->getAttributes(true, false)];
        }

        if ($this->maxAggregateLevel <= count($this->aggregateGroups)) {
            $max          = $this->maxAggregateLevel - 1;
            $rawDataCount = count($rawData) - 1;
            for ($k = $rawDataCount; $k >= 0; $k--) {
                if (isset($rawData[$k]['$type']) && $rawData[$k]['$type'] == 'a' && $rawData[$k]['$level'] > $max) {
                    $rawData[$k]['$aggr'] = false;
                }
                if ($max == -1 && $rawDataCount == $k) {
                    $rawData[$k]['$aggr'] = true;
                }
            }
        }


        $data = [
            'data' => $rawData,
            'debug' => [
                'count' => $count,
                'params' => $postedParams,
                'debug' => $criteria,
            ],
            'rel' => [
                'insert_data' => $relChanges['insert'],
                'update_data' => $relChanges['update'],
                'delete_data' => $relChanges['delete'],
            ]
        ];

        return $data;
    }

    public static function generateCriteria($postedParams, $criteria, $field) {
        if (isset($criteria['select']) && stripos($criteria['select'], 'php:') === 0) {
            $criteria['select'] = Helper::evaluate(substr($criteria['select'], 4));
        }

        ## paging criteria
        if (@$criteria['paging'] == '{[paging]}') {
            $criteria['page']     = 1;
            $criteria['pageSize'] = 25;
        }

        if (is_array(@$postedParams['paging'])) {
            if (isset($postedParams['paging']['currentPage'])) {
                $criteria['page'] = $postedParams['paging']['currentPage'];
            } else {
                $criteria['page'] = 1;
            }
            $criteria['pageSize'] = $postedParams['paging']['pageSize'];
        }

        ## order criteria
        if (isset($criteria['order']) && is_string($criteria['order'])) {
            $sql               = $criteria['order'];
            $bracket           = DataSource::generateTemplate($sql, $postedParams, $field);
            $criteria['order'] = str_replace("order by", "", $bracket['sql']);
        }

        ## condition criteria
        if (isset($criteria['condition']) && is_string($criteria['condition'])) {
            $sql     = $criteria['condition'];
            $bracket = DataSource::generateTemplate($sql, $postedParams, $field);

            if ($bracket['sql'] != '') {
                if (substr($bracket['sql'], 0, 5) == 'where') {
                    $criteria['condition'] = substr($bracket['sql'], 5);
                } else {
                    $criteria['condition'] = $bracket['sql'];
                }

                $params             = isset($postedParams['params']) ? $postedParams['params'] : [];
                $criteria['params'] = array_merge($params, $bracket['params']);
            } else if ($bracket['sql'] == '') {
                unset($criteria['condition']);
            }
        }

        $criteria['distinct'] = (@$criteria['distinct'] == 'true' ? true : false);
        if (isset($criteria['paging'])) {
            unset($criteria['paging']);
        }

        if (isset($criteria['select']) && $criteria['select'] == '') {
            unset($criteria['select']);
        }

        foreach ($criteria as $k => $m) {
            if (is_string($m)) {
                $criteria[$k] = stripcslashes($m);
            }
        }

        return $criteria;
    }

    public function getPostName($mode = '') {
        if ($this->relationTo == '' || $this->relationTo == 'currentModel') {
            return $this->name . $mode;
        } else {
            $name = str_replace($this->name, $this->relationTo, $this->renderName);

            if ($mode != '') {
                $name = substr_replace($name, $mode . ']', -1);
            }
            return $name;
        }
    }

    /**
     * @return array me-return array javascript yang di-include
     */
    public function includeJS() {
        return ['data-source.js'];
    }

    /**
     * render
     * Fungsi ini untuk me-render field dan atributnya
     * @return mixed me-return sebuah field dan atribut checkboxlist dari hasil render
     */
    public function render() {
        $execQuery = true;
        if (isset($this->params['where'])) {
            $field = $this->builder->findField(['name' => $this->params['where']]);
            if ($field) {
                foreach ($field['filters'] as $f) {
                    $dateCondition = @$f['defaultOperator'] != '' && @$f['filterType'] == 'date';
                    if (@$f['defaultValue'] != '' || $dateCondition ||
                        @$f['defaultValueFrom'] != '' || @$f['defaultValueTo'] != ''
                    ) {
                        $execQuery = false;
                    }
                }
            }
        }

        if ($execQuery) {
            $this->processQuery();
        } else {
            $this->data = [
                'data' => [],
                'count' => 0,
                'params' => $this->params,
                'debug' => '',
                'rel' => ''
            ];
        }

        return $this->renderInternal('template_render.php');
    }

    public function processQuery() {
        if (is_string($this->params)) {
            $this->params = [];
        }

        if ($this->relationTo == '' || $this->postData == 'No') {
            ## without relatedTo
            switch ($this->fieldType) {
                case "sql":
                    $data = $this->query($this->params);
                    break;
                case "phpsql":
                    $this->sql = $this->execute($this->params);
                    $data      = $this->query($this->params);
                    break;
                case "php":
                    $data = $this->execute($this->params);
                    break;
            }
        } else {
            ## with relatedTo
            $data = $this->getRelated($this->params);
        }

        $this->data = [
            'data' => @$data['data'],
            'count' => @$data['debug']['count'],
            'params' => @$data['debug']['params'],
            'debug' => ($this->debugSql == 'Yes' ? @$data['debug'] : []),
            'rel' => @$data['rel']
        ];
    }

}