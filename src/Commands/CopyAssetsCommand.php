<?php

namespace Momotombo\NativephpSettings\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:settings:copy-assets';

    protected $description = 'Copy assets for Settings plugin';

    public function handle(): int
    {
        if ($this->isAndroid()) {
            $this->info('Android assets copied for Settings');
        }

        if ($this->isIos()) {
            $this->info('iOS assets copied for Settings');
        }

        return self::SUCCESS;
    }
}
