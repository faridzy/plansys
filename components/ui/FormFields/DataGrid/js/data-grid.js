
app.directive('psDataGrid', function ($timeout, $http, $compile, dateFilter) {
    return {
        scope: true,
        compile: function (element, attrs, transclude) {

            return function ($scope, $el, attrs, ctrl) {
                function evalArray(array) {
                    for (i in array) {
                        if (typeof array[i] == "string") {
                            if (array[i].trim().substr(0, 3) == "js:") {
                                eval('array[i] = ' + array[i].trim().substr(3));
                            } else if (array[i].trim().substr(0, 4) == "url:") {
                                var url = array[i].trim().substr(4);
                                array[i] = function (row) {
                                    location.href = eval($scope.generateUrl(url, 'function'));
                                }
                            } else {
                                if (array[i].match(/true/i)) {
                                    array[i] = true;
                                } else if (array[i].match(/false/i)) {
                                    array[i] = false;
                                }
                            }
                        }
                    }
                }

                $('body').on({
                    mouseover: function () {
                        var $container = $(this);
                        $('.ngRow > .ngCellButtonCollapsedDetail').each(function () {
                            var $container = $(this).parent().find('.' + $(this).attr('colt')).find('.ngCellButtonCollapsed');
                            $(this).hide().remove().appendTo($container);
                        });

                        var $detail = $(this).find('.ngCellButtonCollapsedDetail').remove();
                        var offset = {
                            right: $(this).parents('.ngCanvas').width() -
                                    ($(this).parents('.ngCell').css('left').replace('px', '') * 1 +
                                            $(this).parents('.ngCell').width())
                        };
                        $detail.attr('colt', $(this).parents('.ngCell').attr('class').split(' ').pop())
                                .css(offset)
                                .show();

                        $detail.appendTo($(this).parents('.ngRow'));
                        $compile($detail)(angular.element($container).scope());

                        $detail.on({
                            mouseout: function () {
                                $(this).hide().remove().appendTo($container);
                            }
                        });

                    },
                }, '.ngCellButtonCollapsed');

                $scope.pagingKeypress = function (e) {
                    if (e.which == 13) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                }

                $scope.excelModeSelectedRow = null;
                $scope.excelModeSelChange = function (row, event) {
                    $scope.excelModeSelectedRow = row;
                }

                $scope.removeRow = function (row) {
                    if (typeof row == "undefined" || typeof row.rowIndex != 'number') {
                        return;
                    }

                    var index = row.rowIndex;
                    $scope.data.splice(index, 1);
                    $timeout(function () {
                        if ($scope.data.length <= index) {
                            $scope.grid.selectedItems.length = 0;
                        } else {
                            $scope.grid.gridOptions.selectItem(index, true);
                        }
                    }, 0);
                };

                $scope.isNotEmpty = function (data, except) {
                    var except = except || [];
                    var valid = false;
                    for (i in data) {
                        if (except.indexOf(i) >= 0) {
                            continue;
                        }

                        if (data[i] != "") {
                            valid = true;
                        }
                    }
                    return valid;
                }

                $scope.addRow = function (row) {
                    if (typeof $scope.data == "undefined") {
                        $scope.data = [];
                    }

                    var data = {};
                    for (i in $scope.columns) {
                        data[$scope.columns[i].name] = '';
                    }

                    if (typeof row != "undefined" && row != null && typeof row.rowIndex == 'number') {
                        $scope.data.splice(row.rowIndex + 1, 0, data);
                    } else {
                        $scope.data.push(data);
                    }
                }

                $scope.buttonClick = function (row, e) {
                    $btn = $(e.target);

                    if (!$btn.is('a')) {
                        $btn = $btn.parents('a');
                    }

                    if ($btn.attr('confirm')) {
                        if (!confirm($btn.attr('confirm'))) {
                            return false;
                        }
                    }

                    if ($btn.attr('ajax') == 'true') {
                        if ($btn.attr('ajax-success')) {

                            $http.get($btn.attr('href'))
                                    .success(function (data) {
                                        $scope.$eval($btn.attr('ajax-success'), {row: row, data: data});
                                    })
                                    .error(function (data) {
                                        $scope.$eval($btn.attr('ajax-failed'), {row: row, data: data});
                                    });

                        }

                        e.preventDefault();
                        e.stopPropagation();

                        return false;
                    }
                }

                $scope.generateUrl = function (url, type) {
                    var output = '';
                    if (typeof url == "string") {
                        if (url.match(/http*/ig)) {
                            output = url.replace(/\{/g, "'+ row.getProperty('").replace(/\}/g, "') +'");
                        } else if (url.trim() == '#') {
                            output = '#';
                        } else {
                            url = url.replace(/\?/ig, '&');
                            output = "Yii.app.createUrl('" + url.replace(/\{/g, "'+ row.getProperty('").replace(/\}/g, "') +'") + "')";
                        }

                        if (type == 'html') {
                            if (output != '#') {
                                output = '{{' + output + '}}';
                            }
                        }

                    }
                    return output;
                }

                // Type: String
                $scope.generateCellString = function (col) {
                    var format = "";
                    var placeholder = "";
                    var placeholderHtml = "";
                    var emptyVal = "['']";
                    switch (col.inputMask) {
                        case "99/99/9999 99:99":
                            placeholder = "dd/mm/yyyy hh:mm";
                            format = " | dateFormat:'dd/MM/yyyy HH:mm'";
                            emptyVal = "['','0000-00-00 00:00','0000-00-00', '00:00']";
                            break;
                        case "99/99/9999":
                            placeholder = "dd/mm/yyyy";
                            format = " | dateFormat:'dd/MM/yyyy'";
                            emptyVal = "['','0000-00-00 00:00','0000-00-00', '00:00']";
                            break;
                        case "99:99":
                            placeholder = "hh:mm";
                            format = " | dateFormat:'HH:mm'";
                            emptyVal = "['','0000-00-00 00:00','0000-00-00', '00:00']";
                            break;
                    }

                    if (placeholder != "") {
                        placeholderHtml = '<div ng-if="' +
                                emptyVal + '.indexOf(row.getProperty(col.field)) >=0 " style="color:#999">' +
                                placeholder + '</div>';
                    }

                    var ngIf = 'ng-if="' + emptyVal + '.indexOf(row.getProperty(col.field)) < 0 "';

                    var html = '<div class="ngCellText" ng-class="col.colIndex()">\
                                <span ' + ngIf + ' ng-cell-text>{{ row.getProperty(col.field)' + format + '}}</span>\
                                ' + placeholderHtml + '\
                                </div>';
                    return html;
                }
                $scope.generateEditString = function (col) {
                    var uimask = col.inputMask ? "ui-mask='" + col.inputMask + "'" : "";

                    var placeholder = "";
                    switch (col.inputMask) {
                        case "99/99/9999 99:99":
                            placeholder = "placeholder='dd/mm/yyyy hh:mm'";
                            break;
                        case "99/99/9999":
                            placeholder = "placeholder='dd/mm/yyyy'";
                            break;
                        case "99:99":
                            placeholder = "placeholder='hh:mm'";
                            break;
                    }

                    var html = '<input ' + uimask + ' ' + placeholder + ' ng-class="\'colt\' + col.index" \
                                ng-input="COL_FIELD"  ng-model="COL_FIELD" />';
                    return html;
                }

                // Type: Button
                $scope.generateButtons = function (column) {
                    var buttons = column.buttons;
                    var html = '<div class="ngCellButton colt{{$index}}">';
                    var btnSize = 'btn-xs';

                    if (column.buttonCollapsed == 'Yes') {
                        btnSize = 'btn-sm';
                        html += '<div class="ngCellButtonCollapsed">';
                        html += '<div class="ngCellButtonCollapsedDetail">';
                    }

                    for (i in buttons) {
                        var b = buttons[i];
                        var opt = b.options || {};
                        var attr = [];

                        // create url
                        var url = $scope.generateUrl(b.url, 'html');

                        // generate attribute
                        opt['ng-click'] = 'buttonClick(row, $event)';
                        opt.class = (opt.class || '') + ' btn ' + btnSize + ' btn-default';
                        opt.href = url;
                        for (i in opt) {
                            attr.push(i + '="' + opt[i] + '"');
                        }

                        // create html
                        html += '<a ' + attr.join(' ') + '><i class="' + b.icon + '"></i></a>';

                    }

                    if (column.buttonCollapsed == 'Yes') {
                        html += '</div>';
                        html += '<span>...</span></div>';
                    }

                    html += '</div>';
                    return html;
                }

                // Type: Dropdown
                $scope.generateDropdown = function (col) {
                    var id = $scope.name + '-' + col.name + '-dropdownlist';

                    if (col.listType == 'js') {
                        col.listItem = JSON.stringify($scope.$parent.$eval(col.listExpr));
                    }
                    $('<div id="' + id + '">' + col.listItem + '</div>').appendTo('body');

                    var html = '<input';
                    html += ' dg-autocomplete dga-id="' + id + '" dga-must-choose="' + col.listMustChoose + '"';
                    html += ' type="text" ng-class="\'colt\' + col.index"';
                    html += ' ng-input="COL_FIELD" ng-model="COL_FIELD" />';

                    return html;
                }

                // Type: Relation
                $scope.generateEditRelation = function (col) {
                    var html = '<input';
                    html += ' dg-relation params=\'' + JSON.stringify(col.relParams) + '\'';
                    html += ' type="text" ng-class="\'colt\' + col.index"';
                    html += ' ng-input="COL_FIELD_label" ng-model="COL_FIELD_label" />';

                    return html;
                }

                $scope.generateCellRelation = function (col) {

                    var html = '<div class="ngCellText dgr" ng-class="col.colIndex()"';
                    html += 'dgr-id="{{row.getProperty(col.field)}}" dgr-model="' + col.relModelClass + '" ';
                    html += 'dgr-name="' + col.name + '" dgr-labelField="' + col.relLabelField + '" ';
                    html += 'dgr-idField="' + col.relIdField + '">';
                    html += '<span ng-cell-text>{{row.getProperty(col.field + "_label")}}';
                    html += '</span></div>';

                    return html;
                }

                $scope.initGrid = function () {
                    $scope.grid = this;
                }

                $scope.fillColumns = function () {
                    $timeout(function () {
                        var columns = [];
                        $scope.datasource = $scope.$parent[$el.find("data[name=datasource]").text()];

                        if (typeof $scope.datasource != "undefined") {
                            $scope.data = $scope.datasource.data;
                        } else {
                            $scope.data = [];
                        }

                        // prepare gridOptions
                        evalArray($scope.gridOptions);
                        $scope.gridOptions.data = 'data';
                        $scope.gridOptions.plugins = [new ngGridFlexibleHeightPlugin()];
                        $scope.gridOptions.headerRowHeight = 28;
                        $scope.gridOptions.rowHeight = 28;
                        $scope.gridOptions.multiSelect = $scope.gridOptions.multiSelect || false;
                        $scope.gridOptions.enableColumnResize = $scope.gridOptions.enableColumnResize === false ? false : true;

                        if ($scope.data !== null && $scope.columns !== null &&
                                $scope.data.length > 0 && $scope.columns.length == 0) {
                            for (i in $scope.data[0]) {
                                $scope.columns.push({
                                    label: i,
                                    name: i,
                                    options: {}
                                });
                            }
                        }
                        if (typeof $scope.onBeforeLoaded == 'function') {
                            $scope.onBeforeLoaded($scope);
                        }

                        // prepare ng-grid columnDefs
                        var buttonID = 1;
                        for (i in $scope.columns) {
                            var c = $scope.columns[i];

                            // prepare columns
                            evalArray(c.options);
                            switch (c.columnType) {
                                case "string":
                                    var col = angular.extend(c.options || {}, {
                                        field: c.name,
                                        displayName: c.label,
                                        cellTemplate: $scope.generateCellString(c),
                                        editableCellTemplate: $scope.generateEditString(c)
                                    });
                                    break;
                                case "buttons":
                                    var col = angular.extend(c.options || {}, {
                                        field: 'button_' + buttonID,
                                        displayName: c.label,
                                        enableCellEdit: false,
                                        sortable: false,
                                        cellTemplate: $scope.generateButtons(c)
                                    });

                                    if (c.buttonCollapsed == 'Yes') {
                                        col.width = 30;
                                    } else {
                                        col.width = (c.buttons.length * 24) + ((c.buttons.length - 1) * 5) + 20;
                                    }
                                    buttonID++;
                                    break;
                                case "dropdown":
                                    var col = angular.extend(c.options || {}, {
                                        field: c.name,
                                        displayName: c.label,
                                        editableCellTemplate: $scope.generateDropdown(c)
                                    });
                                    break;
                                case "relation":
                                    var col = angular.extend(c.options || {}, {
                                        field: c.name,
                                        displayName: c.label,
                                        cellTemplate: $scope.generateCellRelation(c),
                                        editableCellTemplate: $scope.generateEditRelation(c)
                                    });
                                    break;
                            }
                            columns.push(col);
                        }

                        if (columns.length > 0) {
                            $scope.gridOptions.columnDefs = columns;
                        }

                        // pagingOptions
                        if ($scope.gridOptions['enablePaging']) {
                            $scope.gridOptions.pagingOptions = {
                                pageSizes: [25, 50, 100],
                                pageSize: 25,
                                totalServerItems: $scope.datasource.totalItems,
                                currentPage: 1
                            };
                            var timeout = null;
                            $scope.$watch('gridOptions.pagingOptions', function (paging, oldpaging) {
                                if (paging != oldpaging) {
                                    var ds = $scope.datasource;
                                    var maxPage = Math.ceil($scope.datasource.totalItems / $scope.gridOptions.pagingOptions.pageSize);

                                    if (isNaN($scope.gridOptions.pagingOptions.currentPage) || $scope.gridOptions.pagingOptions.currentPage == '') {
                                        $scope.gridOptions.pagingOptions.currentPage = 1;
                                    }

                                    if ($scope.gridOptions.pagingOptions.currentPage > maxPage) {
                                        $scope.gridOptions.pagingOptions.currentPage = maxPage;
                                    }

                                    if (typeof ds != "undefined") {

                                        if (timeout != null) {
                                            clearTimeout(timeout);
                                        }
                                        timeout = setTimeout(function () {
                                            ds.updateParam('currentPage', paging.currentPage, 'paging');
                                            ds.updateParam('pageSize', paging.pageSize, 'paging');
                                            ds.updateParam('totalServerItems', paging.totalServerItems, 'paging');
                                            ds.query();
                                        }, 100);
                                    }
                                }
                            }, true);
                        }

                        // sortOptions
                        if ($scope.gridOptions['useExternalSorting']) {
                            $scope.gridOptions.sortInfo = {
                                columns: [],
                                fields: [],
                                directions: []
                            };

                            $scope.$watch('gridOptions.sortInfo', function (sort, oldsort) {
                                if (sort != oldsort) {
                                    var ds = $scope.datasource;
                                    if (typeof ds != "undefined") {
                                        var order_by = [];
                                        for (i in sort.fields) {
                                            order_by.push({
                                                field: sort.fields[i],
                                                direction: sort.directions[i]
                                            });
                                        }
                                        ds.updateParam('order_by', order_by, 'order');
                                        ds.query();
                                    }
                                }
                            }, true);
                        }

                        // fixedHeader
                        if ($scope.gridOptions['fixedHeader'] !== false) {
                            $timeout(function () {

                                var $container = $el.parents('.container-full');
                                var $dgcontainer = $el.find(".data-grid-container");
                                var $pager = $el.find(".data-grid-paging");
                                var $cat = $el.find('.data-grid-category');
                                var $topp = $el.find('.data-grid-table .ngTopPanel');
                                var $container = $el.parents('.container-full');
                                var $wc = $el.parent();
                                var formTop = $el.parents("form").offset().top;
                                var pagerTop = $pager.length > 0 ? $pager.offset().top : 0;
                                var pagerHeight = $pager.length > 0 ? $pager.height() : 0;
                                var top = pagerTop - formTop;
                                function fixHead() {
                                    var width = $wc.width();

                                    if ($container.scrollTop() > top) {
                                        if (!$dgcontainer.hasClass('fixed')) {
                                            $dgcontainer.addClass('fixed');
                                        }
                                        $pager.width(width);
                                        $pager.css('top', formTop);

                                        $cat.width(width);
                                        $cat.css('top', formTop + pagerHeight + 10);

                                        $topp.width(width);
                                        $topp.css('top', formTop + pagerHeight + $cat.height() + 10);

                                        $el.find(".data-grid-paging-shadow").show();
                                    } else {
                                        if ($dgcontainer.hasClass('fixed')) {
                                            $dgcontainer.removeClass('fixed');
                                        }
                                        $pager.attr('style', '');
                                        $cat.attr("style", '');
                                        $topp.attr("style", '');
                                        $el.find(".data-grid-paging-shadow").hide();

                                    }
                                }

                                $(window).resize(fixHead);
                                $container.scroll(fixHead);
                                fixHead();
                            }, 0);
                        }

                        // excelMode
                        if ($scope.gridOptions['enableExcelMode']) {
                            $scope.gridOptions['enableCellEdit'] = true;
                            $scope.gridOptions['enableCellSelection'] = true;
                            $scope.gridOptions['afterSelectionChange'] = $scope.excelModeSelChange;
                            $scope.lastFocus = null;

                            var emec = [];
                            if ($scope.gridOptions['excelModeExcludeColumns']) {
                                emec = $scope.$eval($scope.gridOptions['excelModeExcludeColumns']);
                            }
                            for (i in emec) {
                                $scope.datasource.untrackColumns.push(emec[i]);
                            }

                            var excludeColumns = function (data) {
                                var except = [];
                                var cols = [];

                                for (i in $scope.columns) {
                                    if (typeof $scope.columns[i].visible == "undefined" || $scope.columns[i].visible) {
                                        cols.push($scope.columns[i].name);
                                    }
                                }

                                for (i in data) {
                                    if (cols.indexOf(i) < 0) {
                                        except.push(i);
                                    }
                                }

                                for (i in emec) {
                                    except.push(emec[i]);
                                }

                                return except;
                            };

                            $(window).on('focus', function () {
                                if ($scope.lastFocus != null) {
                                    $scope.lastFocus.focus();
                                }
                            });

                            $el.parents('form').submit(function (e) {
                                if ($scope.data.length > 0 || !$el.attr('gridReadyToSubmit')) {
                                    var except = excludeColumns($scope.data[0]);
                                    var newData = [];
                                    var idx = 0;
                                    for (i in $scope.data) {
                                        var row = $scope.data[i];
                                        if ($scope.isNotEmpty(row, except)) {
                                            newData.push(row);
                                        }
                                        idx++;
                                    }

                                    $scope.$apply(function () {
                                        $scope.datasource.data = newData;
                                    });
                                }
                            });

                            $el.on('focus', '[ng-cell] div', function () {
                                $scope.lastFocus = $(this);
                            });

                            $scope.$on('ngGridEventEndCellEdit', function (evt) {
                                var row = evt.targetScope.row;
                                var data = row.entity;
                                var except = excludeColumns(data);

                                if ($scope.isNotEmpty(data, except)) {
                                    if ($scope.data.length - 1 == row.rowIndex) {
                                        $timeout(function () {
                                            $scope.addRow(row);
                                        }, 0);
                                    }
                                }
                            });

                            $timeout(function () {
                                if (typeof $scope.data == "undefined" || $scope.data.length == 0) {
                                    $scope.addRow();
                                } else {
                                    var except = excludeColumns($scope.data[0]);
                                    if ($scope.isNotEmpty($scope.data[$scope.data.length - 1], except)) {
                                        $scope.addRow();
                                    }
                                }
                            }, 0);
                        }

                        //load relation
                        var dgr = {};
                        var dgrCols = [];
                        $timeout(function () {
                            $(".dgr").each(function () {
                                var model = $(this).attr('dgr-model');
                                var id = $(this).attr('dgr-id');
                                var name = $(this).attr('dgr-name');
                                var labelField = $(this).attr('dgr-labelField');
                                var idField = $(this).attr('dgr-idField');

                                if (dgrCols.indexOf(name) < 0) {
                                    dgrCols.push({
                                        name: name,
                                        model: model,
                                        labelField: labelField,
                                        idField: idField
                                    });
                                }

                                dgr[model] = dgr[model] || {};
                                dgr[model][idField] = dgr[model][idField] || [];

                                if (id != "" && dgr[model][idField].indexOf(id) < 0) {
                                    dgr[model][idField].push(id);
                                }
                            });

                            if (dgrCols.length > 0) {
                                var url = Yii.app.createUrl('/FormField/RelationField.dgrInit');

                                function loadRelation(callback) {
                                    $http.post(url, dgr).success(function (data) {
                                        for (rowIdx in $scope.data) {
                                            var row = $scope.data[rowIdx];

                                            for (colIdx in dgrCols) {
                                                var col = dgrCols[colIdx];

                                                try {
                                                    var model = data[col.model][col.idField][row[col.name]];
                                                } catch (e) {
                                                    console.log(data);
                                                }

                                                if (typeof model != "undefined") {
                                                    $scope.datasource.isDataReloaded = true;
                                                    row[col.name + "_label"] = model[col.labelField];
                                                }
                                            }

                                            if (typeof callback == "function") {
                                                callback();
                                            }
                                        }
                                    });
                                }

                                var timeout = null;
                                var reloadRelation = function () {
                                    if (timeout !== null) {
                                        clearTimeout(timeout);
                                    }

                                    timeout = setTimeout(function () {
                                        loadRelation();
                                    }, 50);
                                }
                                reloadRelation();
                                $scope.$watch('data', reloadRelation);
                            }
                        }, 100);
                        if (typeof $scope.onGridLoaded == 'function') {
                            $scope.onGridLoaded($scope.gridOptions);
                        }
                        $scope.loaded = true;

                    }, 0);
                }

                $scope.$watch('datasource.data', function () {
                    if ($scope.datasource != null) {
                        $scope.data = $scope.datasource.data;
                    }
                });

                $scope.reset = function () {
                    location.reload();
                }

                $scope.Math = window.Math;
                $scope.grid = null;
                $scope.name = $el.find("data[name=name]").text();
                $scope.modelClass = $el.find("data[name=model_class]").text();
                $scope.gridOptions = JSON.parse($el.find("data[name=grid_options]").text());
                $scope.columns = JSON.parse($el.find("data[name=columns]").text());
                $scope.loaded = false;
                $scope.onGridLoaded = '';
                $scope.fillColumns();

                $scope.$parent[$scope.name] = $scope;
            }
        }
    };
});