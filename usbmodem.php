<?php
/*
По умолчанию:
1 Команды всегда начинаются с АТ и заканчиваются на <CR> (\r)
2 установлены следующие значения (заводские установки):
    автоустановка скорости, 8-битные данные, 1 стоповый бит, нечетность, управление потоком RTS/CTS.
    Используйте команды +IPR, +IFC и +ICF для изменения этих параметров
3 Ответы начинаются и заканчиваются командами <CR><LF> (\r\n)
4 Последний символ ответа модема = "\n"
    (кроме формата ответа ATV0 DCE) и ATQ1 (подавление результирующего кода).
    • Если синтаксис команды неверен, то выдается ERROR (<CR><LF>ERROR<CR><LF>)
    • Если синтаксис команды верен, но при этом был передан с неверными параметрами,
    то выдается строка +CME ERROR: <Err> или +CMS ERROR: <SmsErr> с соответствующими кодами ошибок,
    если до этого CMEE было присвоено значение 1. По умолчанию, значение CMEE составляет 0,
    и сообщение об ошибке выглядит только как «ERROR».
    • Если последовательность команд была выполнена успешно, то выдается «ОК».
    • +CME ERROR: 515 или +CMS ERROR: 515 может быть получен при попытке выполнения
    следующей АТ-команды до завершения выполнения предыдущей (до получения ответа)
5 В некоторых случаях, например, при AT+CPIN? или добровольных незапрашиваемых сообщениях,
    модем не выдает ОК в качестве ответа.
6 После получения последнего символа AT ответа ждать 1 мс до отсылки новой АТ команды
    иначе синхронизация с модемом может нарушиться
    Если синхронизация была нарушена, то подать "AT" (на 9600 бодах) для повторной синхронизации

Общие команды:
ATQ0 передвать все результирующие коды = OK
ATQ1 результирующие коды блокируются и не передаются = [нет ответа]

ATV0 выдавать результирующие коды в цифровом формате, где 0 означает ОК = 0, без символов <CR><LF> ни в начале ни в конце
ATV1 выдавать результирующие коды в текстовом формате = <CR><LF>ОК<CR><LF>

ATE0 выключить эхо = OK
ATE1 включить эхо = OK

AT+IPR=<n> скорость передачи данных, на которой DTE будет принимать команды = OK
AT+IPR=0 включить автоматическое определение скорости = OK
AT+IPR? текущая скорость = +IPR: 9600\r\nOK\r\n
AT+IPR=? возможное значение = +IPR:(300,600,1200,2400,4800,9600,19200,38400,57600),(115200)\r\nOK\r\n

Время, требуемое на передачу строки символов в модем можно определить как
(strlen("строка") * 8) / AT+IPR? * 1000000

ATZ команда восстанавливает конфигурацию. Разъединяется любой вызов = OK

AT+CFUN=1 Перезагрузить программное обеспечение = OK

http://huawei.mobzon.ru/instruktsii/25-instruktsiya-po-nastrojke-modem
Команды для модема Huawei E1750:
AT^U2DIAG=0 (девайс в режиме только модем)
AT^U2DIAG=1 (девайс в режиме модем + CD-ROM)
AT^U2DIAG=6 (девайс в режиме только сетевая карта)
AT^U2DIAG=268 для E1750 (девайс в режиме модем + CD-ROM + Card Reader)
AT^U2DIAG=276 для E1750 (девайс в режиме сетевой карты + CD-ROM + Card Reader)
AT^U2DIAG=256 (девайс в режиме модем + Card Reader), можно использовать как обычную флешку, отказавшись от установки драйверов модема.
 */
define("SMS_UNREAD", "REC UNREAD");     // вход непрочит
define("SMS_READ", "REC READ");         // вход прочит
define("SMS_UNSENT", "STO UNSENT");     // исх неотпр
define("SMS_SENT", "STO SENT");         // исх отпр
define("SMS_ALL", "ALL");               // все

class usbmodem
{

    public $handle;             // handle of the modem
    public $device;             // OS device name of the modem
    public $timecmd;            // last answer time
    public $errstr = '';        // error string of the class
    public $atspeed = 9600;     // current speed of the AT-interface of the modem (default 9600), bod
    public $answers = array("\r\nOK\r\n", "\r\nERROR\r\n", "CME ERROR:", "CMS ERROR:");

    public function usbmodem($port = '')
    {
        $this->handle = false;

        /*
            $port =
            for Lixux: /dev/ttyUSBx
            for Windows: \\.\COMx (see CreateFile help)
            where x is number or usb modem port
        */
        if (!strlen($port)) {
            $port = file_get_contents("usbmodem.conf");
        }

        if (empty($port)) {
            $this->errstr = "Modem \"$port\" is wrong";
            $this->device = '';
        }
        else {
            $this->errstr = '';
            $this->device = $port;
        }
    }   // usbmodem

    public function open()
    {
        /*
        for Lixux:
        before open ttyUSBx
        add path "/dev/" to parameter open_basedir in php.ini (for security worry himself)
        add user www-data (web-server user) to group dialout (modem's user's group):
        usermod -aG dialout www-data
        restart apache/web-server
        */
        $this->handle = fopen($this->device, "r+b");
        $ret = $this->handle !== false;
        if ($ret) {
            $this->timecmd = microtime(true);
            $this->cmd("AT"); // синхронизация
            $this->cmd("ATZ\r"); // сброс
            $this->cmd("ATE0\r"); // выключить эхо
        } // if( $ret )
        else {
            $this->errstr = "fopen($this->device) error";
        }
        // $php_errormsg

        return ($ret > 0);
    }   // open

    public function close()
    {
        if ($this->handle && fclose($this->handle)) {
            $this->handle = false;
        }
    }   // close

    public function write($str)
    {
        if ($this->handle) {
            // wait 1 millisec from prev. cmd
            while (microtime(true) - $this->timecmd < 0.1) {usleep(100);};

            @stream_set_blocking($this->handle, 1); // block
            $strlen = strlen($str);
            $ret = (int) fwrite($this->handle, $str, $strlen);
            usleep((int) ($strlen * 8 * 1000000 / $this->atspeed)); // sleep important, time is varying
        }
        else {
            $ret = 0;
        }

        return $ret; // always number
    }   // write

    public function read($timeout = 0.0)
    {
        $ret = '';
        if ($this->handle) {
            @stream_set_blocking($this->handle, 0); // unblock

            $timeout = $timeout > 0.0 ? $timeout : (160.0 * 8 / $this->atspeed);
            $this->timecmd = microtime(true);

            while (1) {
                $str = fread($this->handle, 160);
                if (empty($str)) {
                    if (microtime(true) - $this->timecmd > $timeout) {
                        break;
                    }
                } else {
                    $ret .= $str;
                    foreach ($this->answers as $a) {
                        if (strrpos($str, $a) !== false) {
                            $this->timecmd = microtime(true);
                            return $ret;
                        }
                    }
                }
            } // while(1)

            $this->timecmd = microtime(true);
        } // if( $this->handle )
        else {
            $ret = 'Modem not ready';
        }

        return $ret;
    }   // read

    /*
    $cmd - AT command
    return: array of strings: modem answer
    */
    public function cmd($cmd, $timeout = 0.0)
    {
        if (!$this->handle) {
            return 'Modem not ready';
        }

        // write command to modem
        if ($this->write($cmd)) {
            // read modem answer
            $ret = $this->read($timeout);
        }
        else {
            $ret = "\r\ERROR\r\n";
        }

        return explode("\r\n", trim(empty($ret) ? "\r\ERROR\r\n" : $ret));
    }   // cmd

    public function sendsms($phone, $sms)
    {
        if (!$this->handle) {
            return 'Modem not ready';
        }

        // sms text english only!
        $sms = trim($sms);
        if (empty($sms)) {
            return 'SMS is null';
        }

        $phone = trim($phone);
        if (empty($phone)) {
            return 'Phone is null';
        }

        $ret = '';

        if (strlen($phone) == 10)
        {
            $phone = '+7' . $phone;
        }

        // set the device to operate in SMS text mode (0=PDU mode and 1=text mode)
        $this->cmd("AT+CMGF=1\r");

        // AT+CMGS="[number]"\r[text]Control-Z : to send a SMS (in php we have Control-Z with chr(26)). It returns OK or ERROR
        if ($this->write("AT+CMGS=\"" . $phone . "\"\r")) {
            if (!$this->write($sms . chr(26))) {
                $ret = 'WRITE SMS TEXT ERROR';
            }
            else {
                $ret = $this->read(3.0); // remove modem answer
            }
        }
        else {
            $ret = 'AT+CMGS ERROR';
        }

        return $ret;
    }   // sendsms

    /*
    read SMS from modem
    return: array of strings: modem answer
    */
    public function readsms($mode = SMS_ALL)
    {
        if (!$this->handle) {
            return 'Modem not ready';
        }

        // set the device to operate in SMS text mode (0=PDU mode and 1=text mode)
        $this->cmd("AT+CMGF=1\r");
        if (is_numeric($mode)) // read one SMS #$mode
        {
            return $this->cmd("AT+CMGR=" . $mode . "\r", 5.0);
        }
        else // read all SMS for group $mode
        {
            return $this->cmd("AT+CMGL=\"" . $mode . "\"\r", 5.0);
        }
    }   // readsms

    public function delsms($num = 0)
    {
        if (!$this->handle) {
            return 'Modem not ready';
        }

        return $this->cmd("AT+CMGD={$num}\r");
    }   // delsms

    public function reset()
    {
        if (!$this->handle) {
            return 'Modem not ready';
        }

        $this->write("AT+CFUN=1\r");
        return trim($this->read(10.0));
    }
} // class usbmodem
?>
