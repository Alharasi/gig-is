<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
const QUEUE_URL='https://raw.githubusercontent.com/Alharasi/gig-is/main/spotlight/proposals/pending.json';
function req(string $url, ?array $data=null): array {
    $c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'GigApprovalWorker/1']);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data]);
    $b=curl_exec($c);$s=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
    if(!is_string($b))throw new RuntimeException('Network request failed');return [$s,$b];
}
function atomic(string $path,array $value):void {$t=$path.'.next';if(file_put_contents($t,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false)throw new RuntimeException('Cannot persist state');chmod($t,0600);if(!rename($t,$path))throw new RuntimeException('Cannot replace state');}
function readj(string $path):array {if(!is_file($path))return [];$r=json_decode(file_get_contents($path),true);return is_array($r)?$r:[];}
function api(array $cfg,string $method,array $data=[]):array {[$s,$b]=req('https://api.telegram.org/bot'.$cfg['token'].'/'.$method,$data);$r=json_decode($b,true);if($s!==200||empty($r['ok']))throw new RuntimeException('Telegram '.$method.' failed');return $r;}
function say(array $cfg,string $text,array $extra=[]):array {return api($cfg,'sendMessage',array_merge(['chat_id'=>(string)$cfg['chat_id'],'text'=>$text,'disable_web_page_preview'=>'true'],$extra));}
function owner(array $cfg,array $from,array $chat):bool {return !empty($cfg['owner_user_id'])&&!empty($cfg['chat_id'])&&(string)($from['id']??'')===(string)$cfg['owner_user_id']&&(string)($chat['id']??'')===(string)$cfg['chat_id']&&($chat['type']??'')==='private';}
function manifest():?array {[$s,$b]=req(QUEUE_URL.'?t='.time());if($s===404)return null;if($s!==200)throw new RuntimeException('Proposal queue unavailable');$m=json_decode($b,true,512,JSON_THROW_ON_ERROR);if($m===null||$m===[])return null;
    foreach(['proposal_id','release','source_commit','html_sha256','js_sha256','base_sha256','summary','changes','expires_at']as$k)if(!isset($m[$k]))throw new RuntimeException('Incomplete proposal');
    if(!preg_match('/^[a-zA-Z0-9_-]{1,24}$/',$m['proposal_id'])||!preg_match('/^[a-zA-Z0-9_.-]{1,80}$/',$m['release'])||!preg_match('/^[a-f0-9]{40}$/',$m['source_commit']))throw new RuntimeException('Invalid proposal identity');
    foreach(['html_sha256','js_sha256','base_sha256']as$k)if(!preg_match('/^[a-f0-9]{64}$/',$m[$k]))throw new RuntimeException('Invalid proposal hash');
    if(!is_string($m['summary'])||!is_array($m['changes'])||count($m['changes'])<1||count($m['changes'])>10)throw new RuntimeException('Invalid change plan');
    foreach($m['changes']as$c)foreach(['action','title','before','after','reason']as$k)if(!isset($c[$k])||!is_string($c[$k]))throw new RuntimeException('Incomplete change plan');
    $expires=strtotime($m['expires_at']);if($expires===false||$expires>time()+172800)throw new RuntimeException('Invalid expiry');
    $m['_expires']=$expires;$m['_fingerprint']=hash('sha256',$b);return $m;
}
function bundle(array $m):array {$base='https://raw.githubusercontent.com/Alharasi/gig-is/'.$m['source_commit'].'/spotlight/proposals/'.$m['proposal_id'].'/';[$s,$h]=req($base.'index.html');if($s!==200||strlen($h)<10000||strlen($h)>500000||hash('sha256',$h)!==$m['html_sha256'])throw new RuntimeException('Proposal HTML mismatch');
    if(!preg_match('/<meta name="gig-release" content="([a-zA-Z0-9_.-]+)">/',$h,$r)||$r[1]!==$m['release'])throw new RuntimeException('Release mismatch');
    if(!preg_match('/<script src="\/(gig-app-[A-Za-z0-9_.-]+\.js)" defer><\/script>/',$h,$a))throw new RuntimeException('Invalid local script');[$s,$js]=req($base.$a[1]);if($s!==200||strlen($js)>250000||hash('sha256',$js)!==$m['js_sha256'])throw new RuntimeException('Proposal JavaScript mismatch');
    if(!preg_match('/var ideas = (\[.*?\]);\s*var currentIndex/s',$js,$j))throw new RuntimeException('Missing idea data');$ideas=json_decode($j[1],true,512,JSON_THROW_ON_ERROR);if(!is_array($ideas)||count($ideas)<1||count($ideas)>5)throw new RuntimeException('Invalid idea count');$ids=[];foreach($ideas as$i){foreach(['id','title','desc','whyNow','deliverable','pilot','obstacle','clients','firstTen','tools']as$k)if(!isset($i[$k])||$i[$k]==='')throw new RuntimeException('Incomplete idea');if(isset($ids[(string)$i['id']]))throw new RuntimeException('Duplicate ID');$ids[(string)$i['id']]=true;}
    return ['source_url'=>$base.'index.html','html_sha256'=>$m['html_sha256'],'js_sha256'=>$m['js_sha256'],'release'=>$m['release'],'proposal_id'=>$m['proposal_id']];
}
function livehash():string {$p=dirname(__DIR__).'/public_html/index.html';if(!is_file($p))throw new RuntimeException('Live page missing');return hash_file('sha256',$p);}
function proposalText(array $m):string {$t="خطة تحديث Gig — ".$m['proposal_id']."\n\n".$m['summary']."\n";foreach($m['changes']as$c)$t.="\n• ".$c['action'].': '.$c['title']."\nقبل: ".$c['before']."\nبعد: ".$c['after']."\nالسبب: ".$c['reason']."\n";return $t."\nلن يتغير الموقع قبل موافقتك على هذه الخطة.\nصلاحية الخطة حتى: ".$m['expires_at'];}
function notifyProposal(array $cfg,array $m,string $statePath):array {bundle($m);$text=proposalText($m);$tag=substr($m['_fingerprint'],0,12);$buttons=['inline_keyboard'=>[[['text'=>'اعتماد ونشر','callback_data'=>'approve:'.$m['proposal_id'].':'.$tag],['text'=>'رفض','callback_data'=>'reject:'.$m['proposal_id'].':'.$tag]]]];
    if(strlen($text)>6500){$file=__DIR__.'/proposal-plan.txt';file_put_contents($file,$text);api($cfg,'sendDocument',['chat_id'=>(string)$cfg['chat_id'],'document'=>new CURLFile($file,'text/plain','Gig-plan.txt'),'caption'=>'الخطة الكاملة للمقترح '.$m['proposal_id']]);$text="خطة تحديث Gig — ".$m['proposal_id']."\n".$m['summary']."\nراجع الخطة الكاملة في المرفق، ثم اعتمدها أو ارفضها.";}
    $sent=say($cfg,$text,['reply_markup'=>json_encode($buttons,JSON_UNESCAPED_UNICODE)]);$state=['id'=>$m['proposal_id'],'fingerprint'=>$m['_fingerprint'],'status'=>'pending','message_id'=>$sent['result']['message_id'],'manifest'=>$m,'sent_at'=>gmdate('c')];atomic($statePath,$state);return $state;
}
function decide(array $cfg,array $state,string $action,string $statePath):array {if(($state['status']??'')!=='pending'){say($cfg,'هذا المقترح لم يعد بانتظار الموافقة.');return $state;}$m=manifest();if(!$m||$m['_fingerprint']!==$state['fingerprint']||$m['_expires']<time()||livehash()!==$m['base_sha256']){say($cfg,'تغيرت الخطة أو نسخة الموقع أو انتهت صلاحيتها؛ يلزم مقترح جديد قبل النشر.');$state['status']='stale';atomic($statePath,$state);return $state;}
    if($action==='reject'){$state['status']='rejected';$state['decided_at']=gmdate('c');atomic($statePath,$state);say($cfg,'تم رفض المقترح '.$state['id'].'. بقي الموقع كما هو.');return $state;}
    $active=bundle($m);$ap=['status'=>'approved','proposal_id'=>$m['proposal_id'],'release'=>$m['release'],'html_sha256'=>$m['html_sha256'],'js_sha256'=>$m['js_sha256'],'base_sha256'=>$m['base_sha256'],'user_id'=>$cfg['owner_user_id'],'chat_id'=>$cfg['chat_id'],'approved_at'=>gmdate('c'),'fingerprint'=>$m['_fingerprint']];
    $activePath=__DIR__.'/active-source.json';$previous=readj($activePath);atomic(__DIR__.'/approved.json',$ap);atomic($activePath,$active);$state['status']='approved';atomic($statePath,$state);
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/publish.php').' 2>&1',$output,$exitCode);
    $status=readj(dirname(__DIR__).'/public_html/gig-update-status.json');
    if($exitCode===0&&($status['release']??'')===$m['release']&&($status['sha256']??'')===$m['html_sha256']){$state['status']='published';$state['published_at']=gmdate('c');atomic($statePath,$state);say($cfg,'تم تنفيذ المقترح '.$state['id']." والتحقق من النشر.\nhttps://gig.is/");}
    else {atomic($activePath,$previous);$state['status']='failed';atomic($statePath,$state);say($cfg,'تعذر تنفيذ المقترح '.$state['id'].'. لم يتأكد النشر؛ سأحتاج إلى فحص الخطأ قبل إعادة المحاولة.');}
    return $state;
}
if(in_array('--self-test',$argv,true)){$c=['owner_user_id'=>11,'chat_id'=>11];if(!owner($c,['id'=>11],['id'=>11,'type'=>'private'])||owner($c,['id'=>12],['id'=>11,'type'=>'private'])||owner($c,['id'=>11],['id'=>12,'type'=>'private'])||owner($c,['id'=>11],['id'=>11,'type'=>'group']))throw new RuntimeException('Owner test failed');echo "OWNER AUTHORIZATION TESTS PASSED\n";exit;}
$lock=fopen(__DIR__.'/telegram-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$cfgPath=__DIR__.'/telegram.json';$cfg=readj($cfgPath);if(empty($cfg['token'])||empty($cfg['owner_user_id'])||empty($cfg['chat_id'])){echo "CHAT NOT LINKED\n";exit;}
$statePath=__DIR__.'/proposal-state.json';$state=readj($statePath);
try {
    $offset=(int)($cfg['last_update_id']??0)+1;$updates=api($cfg,'getUpdates',['offset'=>(string)$offset,'timeout'=>'0','limit'=>'100','allowed_updates'=>json_encode(['message','callback_query'])]);
    foreach($updates['result']as$u){
        $cfg['last_update_id']=max((int)($cfg['last_update_id']??0),(int)$u['update_id']);atomic($cfgPath,$cfg);
        if(isset($u['callback_query'])){$q=$u['callback_query'];$message=$q['message']??[];if(!owner($cfg,$q['from']??[],$message['chat']??[]))continue;
            api($cfg,'answerCallbackQuery',['callback_query_id'=>$q['id'],'text'=>'تم استلام اختيارك']);$v=explode(':',$q['data']??'');
            if(count($v)===3&&in_array($v[0],['approve','reject'],true)&&$v[1]===($state['id']??'')&&$v[2]===substr($state['fingerprint']??'',0,12)&&(int)($message['message_id']??0)===(int)($state['message_id']??-1))$state=decide($cfg,$state,$v[0],$statePath);
            elseif(($q['data']??'')==='test:approve'){say($cfg,'تم استلام موافقة الاختبار بنجاح. لم يجر تعديل الموقع.');$cfg['approval_test_at']=gmdate('c');atomic($cfgPath,$cfg);}
            elseif(($q['data']??'')==='test:reject')say($cfg,'تم استلام رفض الاختبار. لم يجر تعديل الموقع.');
            continue;
        }
        $m=$u['message']??[];if(!owner($cfg,$m['from']??[],$m['chat']??[]))continue;$text=trim($m['text']??'');
        if(in_array($text,['موافق','رفض','غير موافق'],true)){if(isset($state['message_id'])&&(int)($m['reply_to_message']['message_id']??0)===(int)$state['message_id'])$state=decide($cfg,$state,$text==='موافق'?'approve':'reject',$statePath);else say($cfg,'لربط موافقتك بالخطة الصحيحة، اضغط الزر أسفل الخطة أو رد على رسالتها مباشرة بكلمة موافق.');}
        elseif($text==='/start'||$text==='/status'||$text==='الحالة')say($cfg,'محادثتك مرتبطة بموقع Gig. '.(($state['status']??'')==='pending'?'يوجد مقترح بانتظار موافقتك.':'لا يوجد مقترح بانتظار موافقتك حاليا.').' استقبال الردود يعمل كل خمس دقائق.');
    }
    $new=manifest();if($new&&$new['_expires']>=time()&&($new['_fingerprint']!==($state['fingerprint']??''))){if(livehash()===$new['base_sha256'])$state=notifyProposal($cfg,$new,$statePath);}
    echo "TELEGRAM CHECK COMPLETE; STATE ".($state['status']??'none')."\n";
}catch(Throwable $e){file_put_contents(__DIR__.'/telegram-errors.log',gmdate('c').' '.$e->getMessage()."\n",FILE_APPEND);fwrite(STDERR,"TELEGRAM WORKER FAILED\n");exit(1);}
