<?php

class SqlCriteria extends FormField {

    /** @var string $toolbarName */
    public static $toolbarName = "Sql Criteria";

    /** @var string $category */
    public static $category = "Data & Tables";

    /** @var string $toolbarIcon */
    public static $toolbarIcon = "fa fa-database";
    public $name = '';
    public $label = '';
    public $paramsField = '';
    public $params = array();
    public $baseClass = '';
    public $value = array();
    public $options = array();
    public $modelClassJS = ''; //digunakan untuk menggenerate Preview SQL

    public function getFieldProperties() {
        return array (
            array (
                'label' => 'Base Class',
                'name' => 'baseClass',
                'options' => array (
                    'ng-model' => 'active.baseClass',
                    'ng-change' => 'save();',
                ),
                'listExpr' => 'array(\\\'DataSource\\\',\\\'RelationField\\\',\\\'DataGrid\\\', \\\'DataFilter\\\');',
                'type' => 'DropDownList',
            ),
            array (
                'label' => 'Criteria Field',
                'name' => 'name',
                'options' => array (
                    'ng-model' => 'active.name',
                    'ng-change' => 'changeActiveName()',
                    'ps-list' => 'modelFieldList',
                ),
                'type' => 'DropDownList',
            ),
            array (
                'label' => 'Params Field',
                'name' => 'paramsField',
                'options' => array (
                    'ng-model' => 'active.paramsField',
                    'ng-change' => 'save()',
                    'ps-list' => 'modelFieldList',
                ),
                'type' => 'DropDownList',
            ),
            array (
                'label' => 'Label',
                'name' => 'label',
                'options' => array (
                    'ng-model' => 'active.label',
                    'ng-change' => 'save();',
                    'ng-delay' => '500',
                ),
                'type' => 'TextField',
            ),
            array (
                'label' => 'ModelClassJS',
                'name' => 'modelClassJS',
                'options' => array (
                    'ng-model' => 'active.modelClassJS',
                    'ng-change' => 'save()',
                    'ng-delay' => '500',
                ),
                'type' => 'TextField',
            ),
            array (
                'value' => '<pre style=\"color:#999;font-size:11px;padding:6px;\"><i class=\"fa fa-info-circle\"></i> be sure to set $scope.modelClass in ModelClassJS File
</pre>',
                'type' => 'Text',
            ),
            array (
                'label' => 'Options',
                'name' => 'options',
                'type' => 'KeyValueGrid',
            ),
        );
    }

    public function includeJS() {
        return array('sql-criteria.js');
    }

    public static function convertPagingCriteria($criteria) {
        if (isset($criteria['paging'])) {
            if (is_array(@$criteria['paging']) && count($criteria) == 2) {
                $criteria['page'] = $criteria['paging']['currentPage'];
                $criteria['pageSize'] = $criteria['paging']['pageSize'];
            } else if (is_string($criteria['paging'])) {
                $criteria['page'] = 1;
                $criteria['pageSize'] = 25;
            }
            unset($criteria['paging']);
        }

        if (isset($criteria['page']) && isset($criteria['pageSize'])) {

            $criteria['limit'] = $criteria['pageSize'];
            $criteria['offset'] = ($criteria['page'] - 1) * $criteria['pageSize'];

            unset($criteria['pageSize'], $criteria['page']);
        }

        return $criteria;
    }

    public function actionPreviewSQL() {
        $postdata = file_get_contents("php://input");
        $post = json_decode($postdata, true);
        $criteria = @$post['criteria'] ? $post['criteria'] : array();
        $params = @$post['params'] ? $post['params'] : array();
        $baseClass = $post['baseclass'];


        switch ($baseClass) {
            case "DataGrid":
            case "DataFilter":
            case "RelationField":
                $rel = 'currentModel';
                $name = $post['rfname'];
                $classPath = $post['rfclass'];
                $modelClassPath = $post['rfmodel'];

                $modelClass = array_pop(explode(".", $modelClassPath));
                Yii::import($modelClassPath);

                $class = array_pop(explode(".", $classPath));
                Yii::import($classPath);

                $model = new $modelClass;
                $builder = $model->commandBuilder;

                $fb = FormBuilder::load($classPath);
                $field = $fb->findField(array('name' => $name));
                $rf = new RelationField();
                $rf->builder = $fb;
                $rf->attributes = $field;
                $rf->relationCriteria = $criteria;

                $rf->params = $post['params'];

                $criteria = $rf->generateCriteria('', array());
                $criteria = new CDbCriteria($criteria);

                break;
            case "DataSource":
                $rel = $post['rel'];
                $name = $post['dsname'];
                $classPath = $post['dsclass'];

                $class = array_pop(explode(".", $classPath));
                Yii::import($classPath);

                $model = new $class;
                $builder = $model->commandBuilder;

                $fb = FormBuilder::load($classPath);
                $fb->model = new $model;

                $field = $fb->findField(array('name' => $name));
                $ds = new DataSource();
                $ds->attributes = $field;

                $criteria = DataSource::generateCriteria($params, $criteria, $ds);
                $criteria = SqlCriteria::convertPagingCriteria($criteria);
                $criteria = new CDbCriteria($criteria);

                break;
        }


        if ($rel == 'currentModel') {
            $tableSchema = $model->tableSchema;
        } else {
            $parent = $model::model()->find();

            $relMeta = $model->getMetadata()->relations[$rel];
            $relClass = $relMeta->className;
            $tableSchema = $relClass::model()->tableSchema;

            switch (get_class($relMeta)) {
                case 'CHasOneRelation':
                case 'CBelongsToRelation':
                    if (is_string($relMeta->foreignKey)) {
                        $criteria->addColumnCondition([$relMeta->foreignKey => $parent->id]);
                    }
                    break;
                case 'CManyManyRelation':
                case 'CHasManyRelation':
                    //without through
                    if (is_string($relMeta->foreignKey)) {
                        $criteria->addColumnCondition([$relMeta->foreignKey => $parent->id]);
                    }

                    //with through
                    //todo..
                    break;
            }
        }

        $command = $builder->createFindCommand($tableSchema, $criteria);

        $errMsg = '';
        try {
            $command->queryScalar();
        } catch (Exception $e) {
            $errMsg = $e->getMessage();
            $errMsg = str_replace("CDbCommand gagal menjalankan statement", "", $errMsg);
        }

        echo json_encode([
            "sql" => $command->text,
            "error" => $errMsg
        ]);
    }

    public function getInlineJS() {
        $script = '';
        $reflector = new ReflectionClass(get_class($this->model));
        $fn = dirname($reflector->getFileName());
        $jsfile = realpath($fn . "/" . $this->modelClassJS);

        if (is_file($jsfile)) {
            $js = file_get_contents($jsfile);
            return $js;
        }

        return '';
    }

    public function render() {
        $this->options['id'] = $this->renderID;
        $this->options['name'] = $this->renderName;
        $this->addClass('field-box');

        $this->setDefaultOption('ng-model', "model.{$this->originalName}", $this->options);

        return $this->renderInternal('template_render.php');
    }

}