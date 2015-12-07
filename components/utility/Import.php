<?php
use Box\Spout\Writer\WriterFactory;
use Box\Spout\Common\Type;

class Import extends CComponent {
    
    const ETL_PATH = 'app.config.etl';
    public $config = [];
    public $columns = [];
    public $relations = [];
    public $model = null;
    public $data= [];
    public $modelClass = null;
    public $resultFile = '';
    public $resultUrl = '';
    public $lastRow = [];
    public $parentData = [];
    private $loaded = false;
    private $lookup = [];
    private $ignoreCols = [];
    
    public function loadConfig($model, $defaultConfig = []) {
        $dir = Yii::getPathOfAlias(Import::ETL_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $configSuffix = "";
        if (strpos($model, ".") !== false) {
            $m = explode(".", $model);
            $model = $m[0];
            $configSuffix = $m[1];
        }
        
        $filePath = $dir . DIRECTORY_SEPARATOR . $model . $configSuffix . ".php";
        
        if (!class_exists($model)) {
            throw new CException('Failed to load `' . $model . '` Class. Class does not exist!');
        }
        
        if (!is_subclass_of($model, 'ActiveRecord')) {
            throw new CException('Failed to load `' . $model . '`. Model must extends from ActiveRecord Class!');
        }
        
        ## instantiate model class
        $this->modelClass = $model;
        $this->model = new $model;
        
        if (!empty($defaultConfig)) {
            $config = $defaultConfig;
        } else {
            if (is_file($filePath)) {
                $config = include($filePath);
                if (is_null($config)) {
                    throw new CException('Failed to read config file `' . $filePath . '`. Please fix your config!');
                }
            } else {
                $modelColumns = [];
                foreach ($this->model->tableSchema->columns as $key=>$col) {
                    if ($col->isPrimaryKey) {
                        $modelColumns[$key] = 'pk';
                    } else {
                        $modelColumns[$key] = 'default';
                    }
                }
                
                $config = [
                    'columns' => $modelColumns
                ];
            }
        }
        
        ## parse columns definition
        $cols = $this->model->tableSchema->columns;
        if (is_array($config['columns']) && !empty($config['columns'])) {
            ## load columns definition
            $this->columns = $config['columns'];
        } else {
            ## columns definition is not available, generate it from db
            foreach ($cols as $key=>$col) {
                if ($col->isPrimaryKey) {
                    $this->columns[$key] = "pk";
                } else {
                    $this->columns[$key] = "default";
                }
            }
        }
        
        ## ignore columns that is unavailable in model
        foreach ($this->columns as $k=>$c) {
            if (!isset($cols[$k])) {
                $this->ignoreCols[$k] = $c;
            }
        }
        
        if (isset($config['relations'])) {
            if (is_array($config['relations'])) {
                $this->relations = $config['relations'];
                
                foreach ($this->relations as $rname => $rel) {
                    if (!isset($rel['model'])) {
                        throw new CException('Invalid columns configuration  in `' . $filePath . '`.
                        Missing `model` key in `'.$rname .'` relation!');
                    }
                    $relModel = $rel['model'];
                    unset($rel['model']);
                    $this->relations[$rname]['import'] = new Import($relModel, $rel);
                }
            }
        }
        
        foreach ($this->columns as $key=>$col) {
            if (is_string($col)) {
                if ($col == 'pk' || $col == 'default') {
                    $col = ['type' => $col];
                } else {
                    $col =  [
                        'type' => 'function',
                        'value' => $col
                    ];
                }
                $this->columns[$key] = $col;
            }
            switch ($col['type']) {
                case 'function':
                    if (!isset($col['value'])) {
                        throw new CException('Invalid columns configuration  in `' . $filePath . '`.
                        Missing `value` key, please provide function name to execute in `value` key!');
                    }
                    break;
                case 'lookup':
                    if (!isset($col['from'])) {
                        throw new CException('Invalid columns configuration  in `' . $filePath . '`.
                        ['.$key.'] Missing `from` key, please provide table name to refer to!');
                    }
                    if (!isset($col['return'])) {
                        throw new CException('Invalid columns configuration  in `' . $filePath . '`.
                        ['.$key.'] Missing `return` key, please provide return column condition !');
                    }
                    if (!isset($col['condition'])) {
                        throw new CException('Invalid columns configuration  in `' . $filePath . '`.
                        ['.$key.'] Missing `condition` key, please provide lookup condition !');
                    }
                    
                    if (isset(Yii::app()->db->schema->tables[$col['from']])) {
                        if (!isset($this->lookup[$col['from']])) {
                            $this->lookup[$col['from']] = [
                                'schema' => Yii::app()->db->schema->tables[$col['from']],
                                'hash' => [
                                    $key => []
                                ]
                            ];
                        } else {
                            $this->lookup[$col['from']]['hash'][$key] = [];
                        }
                    } else {
                        throw new CException('Invalid columns configuration  in `' . $filePath . '`.
                        ['.$key.'] table `'.$col['from'].'` is not found!');
                    }
                    break;
            }
        }
        
        $this->config = $config;
        $this->loaded = true;
        return true;
    }
    
    private function lookup(&$attrs, $col, $key, $row) {
        $errors = [];
        $hashKey = [];
        
        ## replace condition
        $condition = preg_replace_callback( "/{([^.}]*)\.?([^}]*)}/", function($var) use($key, $row, &$errors, &$hashKey) {
            $ref = $var[1] == 'row' ? $row : $this->parentData;
            
            if (!isset($ref[$var[2]])) {
                $errors[$var[2]] = "Error in `{$key}`. key '{$var[0]}' untuk kondisi lookup tidak ditemukan!";
            } else {
                $hashKey[$var[2]] = $ref[$var[2]];
            }
            
            return @$ref[$var[2]];
        }, $col['condition']);
        
        ## if one of column condition variables is not found, then skip this row
        if (!empty($errors)) {
            return $errors;
        }
        
        $lrow = null;
        $hashKey = json_encode($hashKey);
        if (isset($this->lookup[$col['from']]['hash'][$hashKey])) {
            ## lookup from hash table if row is already queried
            $lrow = $this->lookup[$col['from']]['hash'][$hashKey];
        } else {
            ## if hashtable is not found then execute query
            $sql = " SELECT * FROM {$col['from']} WHERE {$condition}";
            $lrow = Yii::app()->db->createCommand($sql)->queryRow();
        }
        
        ## if lookup row are found, then
        $into = isset($col['into']) ? $col['into'] : $key;
        if (isset($lrow[$col['return']])) {
            
            ## assign it into attributes
            $attrs[$into] = $lrow[$col['return']];
            $this->lookup[$col['from']]['hash'][$hashKey] = $lrow;
        } else {
            ## lookup is NOT found, if there is notfound stetement then execute it
            if (isset($col['notfound'])) {
                switch ($col['notfound']['action']) {
                    case 'return':
                        $attrs[$into] = $row[$key];
                        break;
                    case 'insert':
                        if (!isset($this->lookup[$col['from']])) {
                            $this->lookup[$col['from']] = [
                                'schema' => Yii::app()->db->schema->tables[$col['from']],
                                'hash' => [
                                    $key => []
                                ]
                            ];
                        }
                        $pk = $this->lookup[$col['from']]['schema']->primaryKey;
                        if (!is_string($pk)) {
                            return 'Import does not support inserting multiple primary key in lookup!';
                        }
                        
                        ## insert data
                        $insert = $col['notfound']['data'];
                        foreach ($insert as $k=>$i) {
                            if (is_array($i)) {
                                switch ($i['type']) {
                                    case 'lookup':
                                        $this->lookup($insert, $i, $k, array_merge($row, $attrs));
                                        break;
                                }
                            } else if (is_string($i)) {
                                $insert[$k] = preg_replace_callback( "/{([^.}]*)\.?([^}]*)}/", 
                                    function($var) use($key, $row) {
                                        $ref = $var[1] == 'row' ? $row : $this->parentData;
                                        return @$ref[$var[2]];
                                    }, $i);
                            }
                        }
                        
                        Yii::app()->db->createCommand()->insert($col['from'], $insert);
                        $insert[$pk] = Yii::app()->db->getLastInsertID(); 
                        
                        $this->lookup[$col['from']]['hash'][$hashKey] = $insert;
                        
                        ## assign inserted id into attrs
                        $attrs[$into] = $insert[$col['return']];
                    break;
                    case 'lookup':
                        $coldef= $col['notfound'];
                        unset($coldef['action']);
                        return $this->lookup($attrs, $coldef, $key, array_merge($row, $attrs));
                        break;
                    case 'function': 
                        ## evaluate function, using parameters
                        $attrs[$into] = Helper::evaluate($col['notfound']['value'], [
                            'row'=> $row,
                        ] + $params);
                        break;
                }
            }
        }
        
        return true;
    }
    
    public function importRow($row, $params = []) {
        if (!$this->loaded) {
            throw new CException('Import configuration must be loaded before importing!');
        }
        
        $modelClass = $this->modelClass;
        $attrs = [];
        $pks = [];
        $data = [];
        $resolveCol = [];
        
        ## execute function when beforeLookup
        foreach ($this->columns as $key => $col) {
            if (@$col['type'] == 'function') {
                if (@$col['when'] == 'beforeLookup') {
                    ## evaluate function, using parameters
                    $expr = preg_replace_callback( "/{([^.}]*)\.?([^}]*)}/", 
                        function($var) use($key, $row) {
                            $ref = $var[1] == 'row' ? $row : $this->parentData;
                            return @$ref[$var[2]];
                        }, $col['value']);
                    
                    $attrs[$key] = Helper::evaluate($expr, [
                        'row'=> $row,
                    ] + $params);
                    
                    if (@$col['show'] === true) {
                        $data[$key] = $row[$key];
                    }
                }
            }
        }
        
        ## loop each column, and determine how to fill it's value
        foreach ($this->columns as $key => $col) {
            switch ($col['type']) {
                case 'pk':
                    if (@$row[$key] != '') {
                        $pks[$key] = $row[$key];
                        $attrs[$key] = $row[$key];
                    } 
                    $resolveCol[] = $key;
                    $data[$key] = @$row[$key];
                    
                    break;
                case 'default':
                    if (!isset($row[$key])) {
                        return [[
                            $key => 'Field ' . $key . ' tidak ada!'
                        ]];
                    }
                    
                    $resolveCol[] = $key;
                    if ($row[$key] == '' && $this->model->tableSchema->columns[$key]->isForeignKey) {
                        continue;
                    }
                    $attrs[$key] = $row[$key];
                    $data[$key] = $row[$key];
                    break;
                case 'lookup':
                    $result = $this->lookup($attrs, $col, $key, array_merge($row, $attrs, $data));
                    if ($result !== true) {
                        return $result;
                    }
                    if (@$col['show'] !== false) {
                        $into = isset($col['into']) ? $col['into'] : $key;
                        if (isset($attrs[$into])) {
                            $data[$into] = $attrs[$into];
                        } 
                        else if (isset($row[$into])) {
                            $data[$key] = $row[$into];
                        } 
                    }
                    
                    break;
            }
        }
        
        ## execute function when afterLookup
        foreach ($this->columns as $key => $col) {
            if (@$col['type'] == 'function') {
                if (@$col['when'] == 'afterLookup' || !isset($col['when'])) {
                    $expr = preg_replace_callback( "/{([^.}]*)\.?([^}]*)}/", 
                        function($var) use($key, $row) {
                            $ref = $var[1] == 'row' ? $row : $this->parentData;
                            return @$ref[$var[2]];
                        }, $col['value']);
                        
                    if ($expr =='Pass') {
                        var_dump($expr, $col);
                        die();
                    }
                    
                    $attrs[$key] = Helper::evaluate($expr, [
                        'row'=> $row,
                    ] + $params);
                    
                    if (@$col['show'] === true) {
                        $data[$key] = $row[$key];
                    }
                }
            }
        }
        
        ## load model class when available, insert it when not exist
        $model = null;
        if (!empty($pks)) {
            $model = $modelClass::model()->findByAttributes($pks);
        }
        if (is_null($model)) {
            $model = new $modelClass;
        }
        
        ## assign row vars, then save it
        $model->attributes = $attrs;
        if ($model->save()) {
            foreach ($resolveCol as $rc) {
                if (isset($this->ignoreCols[$rc])) continue;
                $data[$rc] = $model->{$rc};
            }
            
            foreach ($this->relations as $rname=>$rel) {
                $initData =  array_merge($row, $attrs, $data);
                if (isset($rel['condition'])) {
                    $expr = preg_replace_callback( "/{([^.}]*)\.?([^}]*)}/", 
                        function($var) use($initData) {
                            return @$initData[$var[2]];
                        }, $rel['condition']);
                    
                    if (!Helper::evaluate($expr)) {
                        continue;
                    }
                }
                
                $relAttrs = $initData;
                foreach ($rel['columns'] as $key=>$col) {
                    $rkey = $rname . '_' . $key;
                    
                    if (isset($row[$rkey])) {
                        $relAttrs[$key] = $row[$rkey];
                    }
                }
                
                $rel['import']->parentData = array_merge($model->attributes, $initData);
                $res = $rel['import']->importRow($relAttrs);
                if ($res !== true) {
                    
                    $errors = [];
                    foreach ($res as $k=>$e) {
                        $errors[] = $k . " => " . @$e[0];
                    }
                    
                    return [
                        'relation ' . $rname => $errors
                    ];
                }
                $data = $data + $rel['import']->lastRow;
            }
            $this->data[] = $data;
            $this->lastRow = $data;
            return true;
        } else {
            return $model->errors;
        }
    }
    
    public function saveExcel() {
        if (!empty($this->data)) {
            $data = $this->data;
            array_unshift($data, array_keys($data[0]));
            
            $path = Yii::getPathOfAlias('root.assets.import');
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
            
            $filename = "import-". Helper::camelToSnake($this->modelClass) . '-' . date("Y-m-d~H.i.s"). '.xlsx';

            $this->resultFile = $path . DIRECTORY_SEPARATOR . $filename;
            $this->resultUrl = Yii::app()->baseUrl . '/assets/import/' . $filename;
            
            $writer = WriterFactory::create(Type::XLSX);
            $writer->openToFile($this->resultFile); 
            $writer->addRows($data); 
            $writer->close();
            
            return $this->resultUrl;
        }
    }
    
    public function __construct($model, $defaultConfig = []) {
        $this->loadConfig($model, $defaultConfig);
    }
    
}
    