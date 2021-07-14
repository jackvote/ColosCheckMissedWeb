<?php
namespace GrapheneNodeClient\examples\Broadcast;
use GrapheneNodeClient\Commands\Commands;
use GrapheneNodeClient\Commands\CommandQueryData;
use GrapheneNodeClient\Commands\Single\BroadcastTransactionSynchronousCommand;
use GrapheneNodeClient\Connectors\WebSocket\GolosWSConnector;
use GrapheneNodeClient\Tools\Transaction;

$check_time=microtime();

require_once "vendor/autoload.php";
$connector = new GolosWSConnector();

//================= witness setting begin =================
$witness="jackvote";
$wif='5Qwerty...';
$keyon='GLS7PGuVBUCVcRmm9eFrYQu99oPHxGTV18BbJszNDsGs5v8vANx8k';
$url="https://golos.id/@jackvote/jackvote---v-delegaty";
$reason="AutoDisableWeb";
$timewait=10*60; // включаем через 10 минут

if  ( isset($_GET['m']) ) { // ручное отключение ?m=off / включение ?m=on
    updateManual($wif, $witness, $url, $keyon, $connector, $_GET['m']);
}

if (file_exists($witness.".disable")==true) {
    echo "Manual disable - remove flag to enable\n";
} else {
    checkWitness($witness, $wif, $keyon, $url, $reason, $timewait, $connector); // проверка на пропущенные блоки и [де]активация
}
//=================  witness setting end  =================


//================= witness setting begin =================
// скопируйте и отредактируйте содержимое предыдущего блока
//=================  witness setting end  =================


//#################### fuction ############################

function checkWitness($witness, $wif, $keyon, $url, $reason, $timewait, $connector) {
    $keyoff="GLS1111111111111111111111111111111114T1Anm";
    
    $line=file_get_contents("log/".$witness.".last"); // GET
    list($timeold, $count)=explode("|", $line); // время проверки и количество пропущенных блоков

    $obj=GetWitnessesByVote($witness, $connector);
    
    $log=date("d-m-Y H:i:s", time())."|".$obj['total_missed']."|".$witness."|".$obj['last_confirmed_block_num']."\n";
    $file="log/".$witness;

    if (file_exists("log/".$witness.".last")==false) { // первый запуск скрипта
        file_put_contents("log/".$witness.".last", time()."|".$obj['total_missed']); // записывем пропущенные блоки и время
        file_put_contents($file, "C|".$log, FILE_APPEND | LOCK_EX); // пишем лог
        echo "Create last";
    }

    if ($obj['total_missed']>$count && $count>0) {
        
        file_put_contents("log/".$witness.".last", time()."|".$obj['total_missed']); // записываем пропущенные блоки
        if ($obj['signing_key']<>$keyoff) {
            updateWitness($wif, $witness, $url, $keyoff, $connector); // disable
            //$obj['signing_key']=$keyoff;
            file_put_contents($file, "D|".$log, FILE_APPEND | LOCK_EX); // пишем лог
            echo "Disable";
        } else {
            file_put_contents($file, "N|".$log, FILE_APPEND | LOCK_EX); // пишем лог
            echo "Now disabled<br>";
        }
    } else {
        if ( $obj['signing_key']==$keyoff ) {
            if ( (time()-$timeold)>$timewait ) {
                updateWitness($wif, $witness, $url, $keyon, $connector); // enable
                file_put_contents($file, "E|".$log, FILE_APPEND | LOCK_EX); // пишем лог
                echo "Enable<br>";
            } else {                                                        // wait
                file_put_contents($file, "W|".$log, FILE_APPEND | LOCK_EX); // пишем лог
                echo "Wait<br>";
            }
        } else {
            echo "All right ".$witness.":".$count."<br>";                                           // no problem
            //file_put_contents($file, "S|".$log, FILE_APPEND | LOCK_EX); // пишем лог
        }
    }
}

function GetWitnessesByVote($witness, $connector) {
        $command = new Commands($connector);
        $command = $command->get_witnesses_by_vote();

        $commandQuery = new CommandQueryData();
        $commandQuery->setParamByKey('0', $witness);
        $commandQuery->setParamByKey('1', 1);
        $content = $command->execute($commandQuery);
        return $content['result'][0];
} // getWitness

function updateWitness($wif, $witness, $url, $key, $connector) {
// Запись ключа делегата
    //$chainName = $connector->getPlatform();

    $tx = Transaction::init($connector);
    $tx->setParamByKey(
    '0:operations:0',
    [
        'witness_update',
        [
            'owner'             => $witness,
            'url'               => $url,
            'block_signing_key' => $key,
            'props'             =>
                [
                    'account_creation_fee' => '10.000 GOLOS',
                    'maximum_block_size'   => 65536,
                    'sbd_interest_rate'    => 1000
                ],
            'fee' => '0.000 GOLOS'
        ]
    ]
    );

    Transaction::sign('golos', $tx, ['active' => $wif]);

    $command = new BroadcastTransactionSynchronousCommand($connector);
    $answer = $command->execute($tx);
    
    echo PHP_EOL . '<pre>' . print_r($answer, true) . '<pre>';
  
} // updateWitness

function updateManual($wif, $witness, $url, $keyon, $connector, $manual) {
    $ip = $_SERVER['REMOTE_ADDR'];
    if  ($manual=='on') { // ручное включение
        updateWitness($wif, $witness, $url, $keyon, $connector);
        unlink("log/".$witness.".last"); // удаляем. Через минуту создастся с актуальным содержимым
        unlink($witness.".disable"); // удаляем флаг ручного отключения
        echo "Manual enable";
        file_put_contents("log/log.txt", date("d-m-Y H:i:s", time())."|".$ip."|Enable|".$witness."\n", FILE_APPEND | LOCK_EX);
        exit;
    }

    if  ($manual=='off') { // ручное отключение
        $keyoff="GLS1111111111111111111111111111111114T1Anm";
        updateWitness($wif, $witness, "Manual disable", $keyoff, $connector);
        echo "Manual disable";
        file_put_contents($witness.".disable", date("d-m-Y H:i:s", time())."|".$ip); // создаём флаг для игнорирования автоматического включения
        file_put_contents("log/log.txt", date("d-m-Y H:i:s", time())."|".$ip."|Disable|".$witness."\n", FILE_APPEND | LOCK_EX);
        exit;
    }

} // updateManual


echo "<br>Ok. ".(microtime()-$check_time);
?>
