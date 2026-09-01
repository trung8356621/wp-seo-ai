<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
$site=App\Models\Site::find(2);
$base='https://maybalotuixachgiare.com';
$write=trim((string)$site->getMeta('seo_migration_token'));
$read=trim((string)$site->getMeta('seo_read_token'));
foreach([
  '/wp-json/omi-seo-ai/v1/plugin/update-check?force_refresh=1',
  '/wp-json/omi-seo-ai/v1/updates/check?force_refresh=1',
  '/wp-json/omi-seo-ai/v1/bridge/update?force_refresh=1',
] as $path){
  $r=Illuminate\Support\Facades\Http::timeout(60)->acceptJson()->withToken($read)->get($base.$path);
  echo "$path => ".$r->status()." ".substr($r->body(),0,300)."\n\n";
}
$caps=Illuminate\Support\Facades\Http::timeout(30)->acceptJson()->withToken($read)->get($base.'/wp-json/omi-seo-ai/v1/capabilities');
$j=$caps->json();
echo "bridge=".($j['bridge_version']??$j['profile']['bridge_version']??'?')."\n";
echo "manual_update=".json_encode($j['capabilities']['manual_update']??$j['manual_update']??null)."\n";
