<?php
namespace App\Support;
class LocalAvatarProvider implements \Filament\AvatarProviders\Contracts\AvatarProvider {
 public function get(\Illuminate\Database\Eloquent\Model $record):string {
 $initial=htmlspecialchars(mb_strtoupper(mb_substr($record->name??'E',0,1)),ENT_XML1,'UTF-8');
 return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" rx="32" fill="#176e66"/><text x="32" y="41" font-family="sans-serif" font-size="26" text-anchor="middle" fill="white">'.$initial.'</text></svg>');
 }
}
