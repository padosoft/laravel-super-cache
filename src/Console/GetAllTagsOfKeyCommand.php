<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Padosoft\SuperCache\SuperCacheManager;

class GetAllTagsOfKeyCommand extends Command
{
    protected $signature = 'supercache:get-tags {key}';
    protected $description = 'Get all tags associated with a key';

    protected $cacheManager;

    public function __construct(SuperCacheManager $cacheManager)
    {
        parent::__construct();
        $this->cacheManager = $cacheManager;
    }

    public function handle()
    {
        $key = $this->argument('key');
        $tags = $this->cacheManager->getTagsOfKey($key);
        $this->info('Tags for key ' . $key . ': ' . implode(', ', $tags));
    }
}
