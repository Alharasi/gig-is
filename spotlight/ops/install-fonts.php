<?php
if(PHP_SAPI!=='cli') exit;
umask(0022);
function fetchFontAsset($url){$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_USERAGENT=>'Mozilla/5.0']);$r=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if(!is_string($r)||$status!==200)throw new Exception('Font download failed');return $r;}
$web='/home/adigekrz/public_html';$url='https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap';$css=fetchFontAsset($url);
preg_match_all('~url\((https://fonts\.gstatic\.com/[^)]+)\)~',$css,$m);if(count($m[1])<1)throw new Exception('No fonts found');
if(!is_dir($web.'/gig-fonts'))mkdir($web.'/gig-fonts',0755);
foreach(array_unique($m[1]) as $asset){$ext=pathinfo(parse_url($asset,PHP_URL_PATH),PATHINFO_EXTENSION);if(!in_array($ext,['woff2','woff','ttf']))throw new Exception('Unexpected font format');$name=substr(hash('sha256',$asset),0,20).'.'.$ext;$path=$web.'/gig-fonts/'.$name;if(!is_file($path)){if(file_put_contents($path,fetchFontAsset($asset))===false)throw new Exception('Cannot write font');chmod($path,0644);}$css=str_replace($asset,'/gig-fonts/'.$name,$css);}
if(file_put_contents($web.'/gig-fonts.css',$css)===false)throw new Exception('Cannot write font CSS');chmod($web.'/gig-fonts.css',0644);echo 'LOCAL FONTS READY '.count(array_unique($m[1]))."\n";
