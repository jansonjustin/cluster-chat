<?php
// Raw Wyoming protocol debugger — shows exactly what Piper sends back
// https://chat.cluster.home/debug_wyoming.php

$host  = $_GET['host']  ?? 'piper';
$port  = (int)($_GET['port']  ?? 10200);
$voice = $_GET['voice'] ?? 'en_US-amy-medium';
$text  = $_GET['text']  ?? 'Hello';
$run   = isset($_GET['run']);
$fmt   = $_GET['fmt'] ?? 'A';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Wyoming Raw Debug</title>
<style>
body{background:#05080c;color:#d0dce8;font-family:monospace;font-size:12px;padding:20px}
h2{color:#00c8ff}label{color:#6a8aa8;display:block;margin-top:8px;font-size:11px;text-transform:uppercase}
input{background:#0d1219;border:1px solid #243545;color:#d0dce8;padding:5px 8px;border-radius:4px;width:300px}
button{margin-top:12px;padding:8px 20px;background:#00c8ff;color:#05080c;border:none;border-radius:4px;font-weight:bold;cursor:pointer}
pre{background:#090d13;border:1px solid #1a2a3a;border-radius:6px;padding:16px;white-space:pre-wrap;overflow-x:auto;margin-top:16px}
.ok{color:#00e57a}.err{color:#ff4466}.warn{color:#ffaa00}.dim{color:#6a8aa8}
a{color:#00c8ff}
</style>
</head>
<body>
<h2>Wyoming Raw Protocol Debug</h2>
<form method="get">
  <label>Host</label><input name="host" value="<?=htmlspecialchars($host)?>">
  <label>Port</label><input name="port" value="<?=$port?>" style="width:80px">
  <label>Voice</label><input name="voice" value="<?=htmlspecialchars($voice)?>">
  <label>Text</label><input name="text" value="<?=htmlspecialchars($text)?>">
  <input type="hidden" name="fmt" value="<?=htmlspecialchars($fmt)?>">
  <button type="submit" name="run" value="1">Run</button>
</form>
<p style="color:#6a8aa8;font-size:11px">
  Format:
  <a href="?host=<?=urlencode($host)?>&port=<?=$port?>&voice=<?=urlencode($voice)?>&text=<?=urlencode($text)?>&fmt=A&run=1">A: minimal (voice=null)</a> |
  <a href="?host=<?=urlencode($host)?>&port=<?=$port?>&voice=<?=urlencode($voice)?>&text=<?=urlencode($text)?>&fmt=B&run=1">B: with voice name</a> |
  <a href="?host=<?=urlencode($host)?>&port=<?=$port?>&voice=<?=urlencode($voice)?>&text=<?=urlencode($text)?>&fmt=describe&run=1">Describe handshake first</a>
</p>

<?php if ($run): ?>
<pre id="out"><?php

function out($text, $cls='') {
    if ($cls) echo "<span class=\"$cls\">".htmlspecialchars($text)."</span>\n";
    else echo htmlspecialchars($text)."\n";
    ob_flush(); flush();
}

out("=== Wyoming Raw Debug (format=$fmt) ===", 'ok');
out("Connecting to $host:$port ...");

$sock = @fsockopen($host, $port, $errno, $errstr, 5);
if (!$sock) { out("CONNECT FAILED: $errstr ($errno)", 'err'); exit; }
out("Connected OK", 'ok');
stream_set_blocking($sock, false);

// Three event formats to try
$events = [
    'A' => json_encode(['type'=>'synthesize','data'=>['text'=>$text,'voice'=>null],'data_length'=>0])."\n",
    'B' => json_encode(['type'=>'synthesize','data'=>['text'=>$text,'voice'=>['name'=>$voice,'language'=>'en_US','speaker'=>null]],'data_length'=>0])."\n",
    'describe' => json_encode(['type'=>'describe','data'=>[],'data_length'=>0])."\n",
];

if ($fmt === 'describe') {
    out("Sending describe first...", 'warn');
    fwrite($sock, $events['describe']);
    // Read one response
    $deadline2 = time() + 5;
    while (time() < $deadline2) {
        $r=[$sock];$w=null;$e=null;
        if (stream_select($r,$w,$e,3) && ($c=fread($sock,65536))) {
            out("Describe response: ".trim($c), 'dim');
            break;
        }
    }
    out("Now sending synthesize...");
    fwrite($sock, $events['A']);
} else {
    $ev = $events[$fmt] ?? $events['A'];
    out("Sending: ".trim($ev), 'dim');
    fwrite($sock, $ev);
}

out("\nWaiting for audio response...", 'warn');

$deadline   = time() + 15;
$total_recv = 0;
$pcm_bytes  = 0;
$line_buf   = '';
$need_bin   = 0;
$event_num  = 0;

while (!feof($sock) && time() < $deadline) {
    $r=[$sock];$w=null;$e=null;
    $ready = stream_select($r,$w,$e,5);
    if ($ready === false) { out("stream_select error",'err'); break; }
    if ($ready === 0) { out("[waiting...]",'dim'); continue; }

    $chunk = fread($sock, 65536);
    if (!$chunk) continue;
    $total_recv += strlen($chunk);
    out(sprintf("[%d bytes, total=%d]", strlen($chunk), $total_recv),'dim');

    $i=0; $len=strlen($chunk);
    while ($i < $len) {
        if ($need_bin > 0) {
            $take = min($need_bin, $len-$i);
            $pcm_bytes += $take;
            $i         += $take;
            $need_bin  -= $take;
            if ($need_bin===0) out("  PCM payload complete. Total PCM: {$pcm_bytes}b",'ok');
        } else {
            $nl = strpos($chunk,"\n",$i);
            if ($nl === false) { $line_buf .= substr($chunk,$i); $i=$len; }
            else {
                $line_buf .= substr($chunk,$i,$nl-$i);
                $i = $nl+1;
                $raw = trim($line_buf); $line_buf='';
                if (!$raw) continue;
                $event_num++;
                out("Event #$event_num: $raw",'warn');
                $evt = json_decode($raw,true);
                if (!$evt) { out("  JSON parse failed",'err'); continue; }
                $type    = $evt['type'] ?? '?';
                $bin_len = (int)($evt['data_length'] ?? $evt['payload_length'] ?? 0);
                $data    = $evt['data'] ?? [];
                out("  type=$type bin_len=$bin_len data=".json_encode($data));
                if ($type==='audio-start') {
                    out("  AUDIO START: rate={$data['rate']} width={$data['width']} ch={$data['channels']}",'ok');
                } elseif ($type==='audio-chunk') {
                    $need_bin = $bin_len;
                    out("  AUDIO CHUNK: expecting {$bin_len}b PCM",'ok');
                } elseif ($type==='audio-stop') {
                    out("  AUDIO STOP. Total PCM: {$pcm_bytes}b",'ok');
                    out("SUCCESS",'ok');
                    break 2;
                } elseif ($type==='error') {
                    out("  ERROR: ".json_encode($data),'err');
                    break 2;
                } else {
                    out("  unknown: $type",'warn');
                }
            }
        }
    }
}
fclose($sock);
out("");
if ($total_recv===0) {
    out("NO DATA from Piper — it received our event but sent nothing back.",'err');
    out("Check: docklogs piper_piper",'warn');
    out("The event format may be wrong, or Piper crashed on startup.",'warn');
} else {
    out("Done. recv={$total_recv}b pcm={$pcm_bytes}b");
}
?>
</pre>
<?php endif; ?>
</body>
</html>
