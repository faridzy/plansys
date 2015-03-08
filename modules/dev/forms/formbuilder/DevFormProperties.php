<?php

class DevFormProperties extends Form {
    public $title;
    public $layoutName;
    public $options = array();
    public $inlineScript = "";
    public $includeJS = array();
    public $includeCSS = array();
    
    public function getForm() {
        return array (
            'title' => 'FormProperties',
            'layout' => array (
                'name' => 'full-width',
                'data' => array (
                    'col1' => array (
                        'type' => 'mainform',
                        'size' => '100',
                    ),
                ),
            ),
            'includeJS' => array (
            ),
        );
    }
    
    public function getFields() {
        return array (
            array (
                'label' => 'Form Title',
                'name' => 'title',
                'options' => array (
                    'ng-model' => '$parent.form.title',
                    'ng-change' => 'saveForm();',
                    'ng-delay' => '500',
                ),
                'type' => 'TextArea',
            ),
            array (
                'label' => 'Form Layout',
                'name' => 'layoutName',
                'listExpr' => 'Layout::listLayout()',
                'iconTemplate' => '<img src=\\"{plansys_url}/static/img/columns/{icon}.png\\" />',
                'fieldWidth' => '150',
                'options' => array (
                    'ng-model' => '$parent.form.layout.name',
                    'ng-change' => 'changeLayoutType(form.layout.name)',
                ),
                'type' => 'IconPicker',
            ),
            array (
                'value' => '{{ layout | json }}

<div ng-if=\"form.layout.data.length >= 1\" 
     class=\"row\">
    <div class=\"col-sm-4\"></div>
    <div class=\"col-sm-6\">
        <table class=\"table table-condensed\">
            <tr ng-repeat=\"col in form.layout.data\">
                <td>{{ col.name }}</td>
                <td>
                    <div class=\"btn\">
                        Minimize
                    </div>
                    
                </td>
            </tr>
        </table>
    </div>
</div>',
                'type' => 'Text',
            ),
            array (
                'label' => 'Inline JS File',
                'name' => 'inlineScript',
                'options' => array (
                    'ng-model' => '$parent.form.inlineJS',
                    'ng-change' => 'saveForm();',
                    'ng-delay' => '500',
                ),
                'type' => 'TextField',
            ),
            array (
                'label' => 'Include JS File',
                'name' => 'includeJS',
                'options' => array (
                    'ng-model' => '$parent.form.includeJS',
                    'ng-change' => 'saveForm()',
                ),
                'type' => 'ListView',
            ),
            array (
                'label' => 'Form Options',
                'show' => 'Show',
                'options' => array (
                    'ng-model' => '$parent.form.options',
                    'ng-change' => 'saveForm()',
                ),
                'type' => 'KeyValueGrid',
            ),
        );
    }
    
}