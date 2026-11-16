<?php
declare(strict_types=1);
// Installed outside the public web root. Only the pinned public repository path is read.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$root = __DIR__;
$target = '/home/adigekrz/public_html/index.html';
$statusFile = '/home/adigekrz/public_html/gig-update-status.json';
$source = 'https://raw.githubusercontent.com/Alharasi/gig-is/main/spotlight/index.html';
$lock = fopen($root . '/publish.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
function request(string $url, ?array $post = null): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>45, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'GigPublisher/1.0']);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!is_string($body) || $code !== 200) throw new RuntimeException('HTTP request failed: ' . $code);
    return $body;
}
function notify(string $message): bool {
    $file=__DIR__.'/telegram.json';
    if (!is_file($file)) return false;
    $cfg=json_decode(file_get_contents($file),true);
    if (!is_array($cfg) || empty($cfg['token']) || empty($cfg['chat_id'])) return false;
    $reply=json_decode(request('https://api.telegram.org/bot'.$cfg['token'].'/sendMessage',['chat_id'=>$cfg['chat_id'],'text'=>$message,'disable_web_page_preview'=>'true']),true);
    if (empty($reply['ok'])) throw new RuntimeException('Telegram delivery failed');
    return true;
}
function statusWrite(array $data): void {
    global $statusFile;
    $tmp=$statusFile.'.next';
    if (file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) throw new RuntimeException('Cannot write status');
    chmod($tmp,0644);
    if (!rename($tmp,$statusFile)) throw new RuntimeException('Cannot publish status');
}
try {
    $html=request($source.'?check='.time());
    if (strlen($html)<10000 || strlen($html)>500000 || !str_contains($html,'<html lang="ar"') || !str_contains($html,'</html>')) throw new RuntimeException('Invalid page structure');
    if (!preg_match('/<meta name="gig-release" content="([a-zA-Z0-9_.-]+)">/',$html,$m)) throw new RuntimeException('Missing release');
    $release=$m[1];
    if (!preg_match('/var ideas = (\[.*?\]);\s*var currentIndex/s',$html,$m)) throw new RuntimeException('Missing ideas');
    $ideas=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
    if (!is_array($ideas) || count($ideas)<1 || count($ideas)>5) throw new RuntimeException('Invalid idea count');
    $ids=[];
    foreach($ideas as $idea) {
        foreach(['id','title','desc','deliverable','pilot','obstacle','whyNow','clients','firstTen','tools'] as $field) if (!isset($idea[$field]) || $idea[$field]==='') throw new RuntimeException('Incomplete idea');
        if (!isset($idea['steps']) || !is_array($idea['steps']) || count($idea['steps'])<1) throw new RuntimeException('Missing steps');
        if (isset($ids[(string)$idea['id']])) throw new RuntimeException('Duplicate idea');
        $ids[(string)$idea['id']]=true;
    }
    $hash=hash('sha256',$html);
    $old=is_file($statusFile)?json_decode(file_get_contents($statusFile),true):[];
    if (is_file($target) && hash_file('sha256',$target)===$hash) {
        $data=is_array($old)?$old:[];
        $data=array_merge($data,['status'=>'current','release'=>$release,'sha256'=>$hash,'idea_count'=>count($ideas),'checked_at'=>gmdate('c')]);
        if (empty($data['telegram_sent_at'])) {
            try { if (notify("تم تحديث Gig\nالنسخة: $release\nعدد الأفكار: ".count($ideas)."\nhttps://gig.is/")) { $data['telegram_sent_at']=gmdate('c'); $data['telegram_status']='sent'; } else $data['telegram_status']='not_configured'; }
            catch(Throwable $e) { $data['telegram_status']='failed'; }
        }
        statusWrite($data); echo "CURRENT $release\n"; exit;
    }
    $backup=null;
    if (is_file($target)) {
        if (!is_dir($root.'/backups')) mkdir($root.'/backups',0700,true);
        $backup=$root.'/backups/'.gmdate('Ymd-His').'-'.substr(hash_file('sha256',$target),0,12).'.html';
        if (!copy($target,$backup)) throw new RuntimeException('Backup failed');
    }
    $tmp=$target.'.gig-next';
    if (file_put_contents($tmp,$html)===false) throw new RuntimeException('Cannot write page');
    chmod($tmp,0644);
    if (!rename($tmp,$target)) throw new RuntimeException('Cannot replace page');
    $verified=false;
    for($i=0;$i<3;$i++) {
        try { if (hash('sha256',request('https://gig.is/?gig_verify='.time().'-'.$i))===$hash) { $verified=true; break; } } catch(Throwable $e) {}
        if ($i<2) sleep(2);
    }
    if (!$verified) {
        if ($backup!==null) { $rollback=$target.'.rollback'; if(copy($backup,$rollback)) { chmod($rollback,0644);rename($rollback,$target); } }
        throw new RuntimeException('Public verification failed; previous page restored when available');
    }
    $data=['status'=>'published','release'=>$release,'sha256'=>$hash,'idea_count'=>count($ideas),'published_at'=>gmdate('c'),'checked_at'=>gmdate('c'),'telegram_status'=>'not_configured'];
    try { if (notify("تم تحديث Gig\nتحليل الفرص وخطط التنفيذ\nالنسخة: $release\nعدد الأفكار: ".count($ideas)."\nhttps://gig.is/")) { $data['telegram_sent_at']=gmdate('c');$data['telegram_status']='sent'; } } catch(Throwable $e) { $data['telegram_status']='failed'; }
    statusWrite($data);echo "PUBLISHED $release VERIFIED\n";
} catch(Throwable $e) {
    file_put_contents($root.'/errors.log',gmdate('c').' '.$e->getMessage()."\n",FILE_APPEND);
    fwrite(STDERR,"PUBLISH FAILED: ".$e->getMessage()."\n");exit(1);
}
