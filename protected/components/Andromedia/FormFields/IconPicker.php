<?php

class IconPicker extends FormField {

    public function getFieldProperties() {
        return array (
            array (
                'label' => 'Field Name',
                'name' => 'name',
                'options' => array (
                    'ng-model' => 'active.name',
                    'ng-change' => 'save()',
                    'ng-form-list' => 'modelFieldList',
                    'searchable' => 'size(modelFieldList) > 5',
                ),
                'list' => array (),
                'showOther' => 'Yes',
                'type' => 'DropDownList',
            ),
            array (
                'label' => 'Label',
                'name' => 'label',
                'options' => array (
                    'ng-model' => 'active.label',
                    'ng-change' => 'save()',
                    'ng-delay' => '500',
                ),
                'type' => 'TextField',
            ),
            array (
                'label' => 'Layout',
                'name' => 'layout',
                'options' => array (
                    'ng-model' => 'active.layout',
                    'ng-change' => 'save();',
                    'ng-delay' => '500',
                ),
                'list' => array (
                    'Horizontal' => 'Horizontal',
                    'Vertical' => 'Vertical',
                ),
                'listExpr' => 'array(\'Horizontal\',\'Vertical\')',
                'fieldWidth' => '6',
                'type' => 'DropDownList',
            ),
            '<hr/>',
            array (
                'label' => 'Label Width',
                'name' => 'labelWidth',
                'fieldWidth' => '3',
                'options' => array (
                    'ng-model' => 'active.labelWidth',
                    'ng-change' => 'save()',
                    'ng-delay' => '500',
                    'ng-disabled' => 'active.layout == \'Vertical\';',
                ),
                'type' => 'TextField',
            ),
            array (
                'label' => 'Box Width',
                'name' => 'fieldWidth',
                'fieldWidth' => '4',
                'postfix' => 'px',
                'options' => array (
                    'ng-model' => 'active.fieldWidth',
                    'ng-change' => 'save()',
                    'ng-delay' => '500',
                ),
                'type' => 'TextField',
            ),
            '<hr/>',
            array (
                'label' => 'Render Empty',
                'name' => 'renderEmpty',
                'options' => array (
                    'ng-model' => 'active.renderEmpty',
                    'ng-change' => 'save();',
                ),
                'list' => array (
                    'Yes' => 'Yes',
                    'No' => 'No',
                ),
                'fieldWidth' => '3',
                'type' => 'DropDownList',
            ),
            array (
                'label' => 'Icon Template',
                'fieldname' => 'iconTemplate',
                'validAction' => 'save()',
                'language' => 'html',
                'type' => 'ExpressionField',
            ),
            array (
                'label' => 'Icon List',
                'fieldname' => 'list',
                'options' => array (
                    'ng-show' => 'active.listExpr == \'\'',
                    'ng-model' => 'active.list',
                    'ng-change' => 'save()',
                    'ng-delay' => '500',
                ),
                'allowSpaceOnKey' => 'Yes',
                'type' => 'KeyValueGrid',
            ),
            array (
                'label' => 'List Expression',
                'fieldname' => 'listExpr',
                'validAction' => 'save();',
                'options' => array (
                    'ng-hide' => 'active.options[\'ng-form-list\'] != null',
                ),
                'desc' => 'WARNING: Using List Expression will replace <i>Radio Button Item</i> with expression result',
                'type' => 'ExpressionField',
            ),
            array (
                'label' => 'Options',
                'fieldname' => 'options',
                'type' => 'KeyValueGrid',
            ),
            array (
                'label' => 'Label Options',
                'fieldname' => 'labelOptions',
                'type' => 'KeyValueGrid',
            ),
        );
    }

    public $label = '';
    public $name = '';
    public $value = '';
    public $list = '';
    public $listExpr = '';
    public $renderEmpty = "No";
    public $layout = 'Horizontal';
    public $iconTemplate = '<i class="fa fa-fw fa-lg {icon}"></i>';
    public $fieldWidth = "265";
    public $labelWidth = 4;
    public $options = array();
    public $labelOptions = array();
    public static $toolbarName = "Icon Picker";
    public static $category = "User Interface";
    public static $toolbarIcon = "fa fa-smile-o";

    public function includeJS() {
        return array('icon-picker.js');
    }

    public function getIcon($value = null) {
        if (is_null($value)) {
            $value = $this->value;
        }
        $template = stripcslashes($this->iconTemplate);
        $template = str_replace("{base_url}", Yii::app()->baseUrl, $template);

        if ($this->renderEmpty == "Yes") {
            return str_replace("{icon}", $value, $template);
        } else {
            return ($value == "" ? "" : str_replace("{icon}", $value, $template));
        }
    }

    public function processExpr() {
        if ($this->listExpr != "") {
            ## evaluate expression
            $this->list = $this->evaluate($this->listExpr, true);

            ## change sequential array to associative array
            if (is_array($this->list) && !Helper::is_assoc($this->list)) {
                $this->list = Helper::toAssoc($this->list);
            }

            if (FormField::$inEditor) {
                if ($this->list > 5) {
                    $this->list = array_slice($this->list, 0, 5);
                    $this->list['z...'] = "...";
                }
            }
        } else if (is_array($this->list) && !Helper::is_assoc($this->list)) {
            $this->list = Helper::toAssoc($this->list);
        }

        return array(
            'list' => $this->list
        );
    }

    public function getlabelClass() {
        if ($this->layout == 'Vertical') {
            $class = "control-label col-sm-12";
        } else {
            $class = "control-label col-sm-{$this->labelWidth}";
        }

        $class .= @$this->labelOptions['class'];
        return $class;
    }

    public function getFieldColClass() {
        return "col-sm-" . ($this->layout == 'Vertical' ? 12 : 12 - $this->labelWidth);
    }

    public function getLayoutClass() {
        return ($this->layout == 'Vertical' ? 'form-vertical' : '');
    }

    public function getErrorClass() {
        return (count($this->errors) > 0 ? 'has-error has-feedback' : '');
    }

    public function render() {
        $this->addClass('form-group form-group-sml', 'options');
        $this->addClass($this->layoutClass, 'options');
        $this->addClass($this->errorClass, 'options');

        $this->processExpr();
        return $this->renderInternal('template_render.php');
    }

}
