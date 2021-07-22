<?php

namespace Symbiote\DataTransfer;

use SilverStripe\Admin\ModelAdmin;

class DataTransferAdmin extends ModelAdmin
{
    private static $url_segment = 'data-transfer';
    private static $menu_title = 'Data transfer';
    private static $managed_models = [
        DataExport::class,
        DataImport::class,
    ];
}
