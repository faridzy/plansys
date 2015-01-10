
<div ps-action-bar class="action-bar-container">
    <div class="action-bar" >
        <div class="title-bar">
            <span class="title"><?= $this->title ?></span>
        </div>
        <?php if ($this->form['layout']['name'] == 'full-width'): ?>
            <div class="print-bar">
                <div class="btn btn-sm btn-default ac-print">
                    <i class="fa fa-print fa-lg "></i>
                </div>
                <div class="btn btn-sm btn-danger ac-exit-print">
                    Exit Print Preview
                </div>
            </div>
        <?php endif; ?>
        <?php if ($this->form['layout']['name'] == 'dashboard'): ?>
            <div class="data hide" name="portlets"><?= json_encode($this->portlets); ?></div>
            <div class="print-bar">
                <div class="ac-portlet-btngroup btn-group" dropdown>
                    <button type="button" class="btn ac-portlet-button btn-sm btn-default dropdown-toggle">
                        <i class="fa fa-bars fa-nm"></i> 
                        <span class="caret"></span>
                    </button>
                    <ul class="ac-portlet-menu dropdown-menu pull-right" role="menu">
                        <hr/>
                        <li dropdown-toggle>
                            <a href="#">
                                <i class="fa fa-pencil fa-nm"></i> Edit Dashboard
                            </a>
                        </li>
                        <li dropdown-toggle>
                            <a href="#">
                                <i class="fa fa-rotate-left fa-nm"></i> Reset Dashboard
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        <div class="link-bar">
            <div ng-show='!formSubmitting'>
                <?= $this->renderLinkBar ?>
            </div>

            <div ng-show='formSubmitting'>
                <i class="fa fa-spin fa-refresh fa-lg" style='margin:10px 10px 0px 0px'></i>
            </div>
        </div>
        <div class="clearfix"></div>
        <?php if ($this->showSectionTab == "Yes"): ?>
            <div class="action-tab" >
                <a href="#<?= strtolower(preg_replace('/[^\da-z]/i', '_', $this->firstTabName)) ?>" top="0" class="active"><?= $this->firstTabName ?></a>
                <div class="clearfix"></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<div id="<?= strtolower(preg_replace('/[^\da-z]/i', '_', $this->firstTabName)) ?>"></div>