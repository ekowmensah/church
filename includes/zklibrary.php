<?php
/**
 * ZKLibrary - PHP library for ZKTeco biometric devices
 * Based on ZKTeco communication protocol
 * 
 * This library provides functionality to connect and communicate with ZKTeco devices
 * including attendance data retrieval, user management, and device control.
 */

class ZKLibrary {
    public $ip;
    public $port;
    public $socket;
    public $session_id;
    public $received_data;
    public $user_data = array();
    public $attendance_data = array();
    public $timeout_sec = 5;
    public $timeout_usec = 0;
    public $protocol = 'UDP';
    
    // Command constants
    const CMD_CONNECT = 1000;
    const CMD_EXIT = 1001;
    const CMD_ENABLEDEVICE = 1001;
    const CMD_DISABLEDEVICE = 1002;
    const CMD_RESTART = 1004;
    const CMD_POWEROFF = 1005;
    const CMD_SLEEP = 1006;
    const CMD_RESUME = 1007;
    const CMD_TEST_TEMP = 1011;
    const CMD_TESTVOICE = 1017;
    const CMD_VERSION = 1100;
    const CMD_CHANGE_SPEED = 1101;
    const CMD_AUTH = 1102;
    const CMD_PREPARE_DATA = 1500;
    const CMD_DATA = 1501;
    const CMD_FREE_DATA = 1502;
    const CMD_PREPARE_BUFFER = 1503;
    const CMD_READ_BUFFER = 1504;
    const CMD_USER_WRQ = 8;
    const CMD_USERTEMP_RRQ = 9;
    const CMD_USERTEMP_WRQ = 10;
    const CMD_OPTIONS_RRQ = 11;
    const CMD_OPTIONS_WRQ = 12;
    const CMD_ATTLOG_RRQ = 13;
    const CMD_CLEAR_DATA = 14;
    const CMD_CLEAR_ATTLOG = 15;
    const CMD_DELETE_USER = 18;
    const CMD_DELETE_USERTEMP = 19;
    const CMD_CLEAR_ADMIN = 20;
    const CMD_USERGRP_RRQ = 21;
    const CMD_USERGRP_WRQ = 22;
    const CMD_USERTZ_RRQ = 23;
    const CMD_USERTZ_WRQ = 24;
    const CMD_GRPTZ_RRQ = 25;
    const CMD_GRPTZ_WRQ = 26;
    const CMD_TZ_RRQ = 27;
    const CMD_TZ_WRQ = 28;
    const CMD_ULG_RRQ = 29;
    const CMD_ULG_WRQ = 30;
    const CMD_UNLOCK = 31;
    const CMD_CLEAR_ACC = 32;
    const CMD_CLEAR_OPLOG = 33;
    const CMD_OPLOG_RRQ = 34;
    const CMD_GET_FREE_SIZES = 50;
    const CMD_ENABLE_CLOCK = 57;
    const CMD_STARTVERIFY = 60;
    const CMD_STARTENROLL = 61;
    const CMD_CANCELCAPTURE = 62;
    const CMD_STATE_RRQ = 64;
    const CMD_WRITE_LCD = 66;
    const CMD_CLEAR_LCD = 67;
    
    /**
     * Constructor
     */
    public function __construct($ip = '', $port = 4370, $protocol = 'UDP') {
        $this->ip = $ip;
        $this->port = $port;
        $this->protocol = $protocol;
        $this->session_id = 0;
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        if ($this->socket) {
            $this->disconnect();
        }
    }
    
    /**
     * Connect to device
     */
    public function connect($ip = '', $port = 4370, $protocol = 'UDP') {
        if ($ip != '') $this->ip = $ip;
        if ($port != 4370) $this->port = $port;
        if ($protocol != 'UDP') $this->protocol = $protocol;
        
        if ($this->protocol == 'TCP') {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        } else {
            $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        }
        
        if (!$this->socket) {
            return false;
        }
        
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, 
            array('sec' => $this->timeout_sec, 'usec' => $this->timeout_usec));
        
        if ($this->protocol == 'TCP') {
            $result = @socket_connect($this->socket, $this->ip, $this->port);
        } else {
            $result = @socket_connect($this->socket, $this->ip, $this->port);
        }
        
        if (!$result) {
            $this->last_error = 'Connection failed: ' . socket_strerror(socket_last_error($this->socket));
            socket_close($this->socket);
            $this->socket = null;
            return false;
        }
        
        // Send connect command
        $command = self::CMD_CONNECT;
        $command_string = '';
        $chksum = 0;
        $session_id = 0;
        $reply_id = 65534;
        
        $buf = $this->createHeader($command, $chksum, $session_id, $reply_id, $command_string);
        
        socket_write($this->socket, $buf, strlen($buf));
        
        $data = socket_read($this->socket, 1024);
        
        if (strlen($data) > 0) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($data, 0, 8));
            $session_id = hexdec($u['h5'] . $u['h6']);
            $this->session_id = $session_id;
            return true;
        }
        
        return false;
    }
    
    /**
     * Disconnect from device
     */
    public function disconnect() {
        if ($this->socket) {
            $this->execCommand(self::CMD_EXIT);
            socket_close($this->socket);
            $this->socket = null;
        }
        return true;
    }
    
    /**
     * Set timeout for socket connection
     */
    public function setTimeout($sec = 5, $usec = 0) {
        $this->timeout_sec = $sec;
        $this->timeout_usec = $usec;
        
        if ($this->socket) {
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, 
                array('sec' => $this->timeout_sec, 'usec' => $this->timeout_usec));
        }
    }
    
    /**
     * Reverse hexadecimal digits
     */
    public function reverseHex($input) {
        $tmp = '';
        for ($i = strlen($input); $i >= 0; $i--) {
            $tmp .= substr($input, $i, 2);
            $i--;
        }
        return $tmp;
    }
    
    /**
     * Encode time to binary data
     */
    public function encodeTime($time) {
        $timestamp = strtotime($time);
        return pack('V', $timestamp);
    }
    
    /**
     * Decode binary data to time
     */
    public function decodeTime($data) {
        $timestamp = unpack('V', $data)[1];
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * Calculate checksum
     */
    public function checkSum($p) {
        $l = count($p);
        $chksum = 0;
        $i = 0;
        
        while ($i < $l) {
            if ($i == ($l - 1) && ($l % 2) == 1) {
                $chksum += ord($p[$i]);
            } else {
                $chksum += ord($p[$i]) + (ord($p[$i + 1]) << 8);
            }
            $i += 2;
        }
        
        $chksum = ($chksum & 0xFFFF);
        return $chksum;
    }
    
    /**
     * Create data header
     */
    public function createHeader($command, $chksum, $session_id, $reply_id, $command_string) {
        $buf = pack('vvVVv', $command, $chksum, $session_id, $reply_id, strlen($command_string));
        $buf .= $command_string;
        
        $chksum = $this->checkSum(str_split($buf));
        $buf = pack('vvVVv', $command, $chksum, $session_id, $reply_id, strlen($command_string));
        $buf .= $command_string;
        
        return $buf;
    }
    
    /**
     * Check if reply is valid
     */
    public function checkValid($reply) {
        return strlen($reply) >= 8;
    }
    
    /**
     * Execute command
     */
    public function execCommand($command, $command_string = '', $offset_data = 8) {
        $chksum = 0;
        $session_id = $this->session_id;
        $reply_id = 65534;
        
        $buf = $this->createHeader($command, $chksum, $session_id, $reply_id, $command_string);
        
        socket_write($this->socket, $buf, strlen($buf));
        
        $data = socket_read($this->socket, 1024);
        
        if ($this->checkValid($data)) {
            return substr($data, $offset_data);
        }
        
        return false;
    }
    
    /**
     * Get number of users
     */
    public function getSizeUser() {
        $command = self::CMD_GET_FREE_SIZES;
        $data = $this->execCommand($command);
        
        if ($data) {
            $size = unpack('V', substr($data, 24, 4));
            return $size[1];
        }
        
        return 0;
    }
    
    /**
     * Get number of attendance records
     */
    public function getSizeAttendance() {
        $command = self::CMD_GET_FREE_SIZES;
        $data = $this->execCommand($command);
        
        if ($data) {
            $size = unpack('V', substr($data, 40, 4));
            return $size[1];
        }
        
        return 0;
    }
    
    /**
     * Disable device
     */
    public function disableDevice() {
        $command = self::CMD_DISABLEDEVICE;
        return $this->execCommand($command);
    }
    
    /**
     * Enable device
     */
    public function enableDevice() {
        $command = self::CMD_ENABLEDEVICE;
        return $this->execCommand($command);
    }
    
    /**
     * Test voice
     */
    public function testVoice() {
        $command = self::CMD_TESTVOICE;
        return $this->execCommand($command);
    }
    
    /**
     * Get device version
     */
    public function getVersion() {
        $command = self::CMD_VERSION;
        $data = $this->execCommand($command);
        
        if ($data) {
            return trim($data);
        }
        
        return false;
    }
    
    /**
     * Restart device
     */
    public function restartDevice() {
        $command = self::CMD_RESTART;
        return $this->execCommand($command);
    }
    
    /**
     * Shutdown device
     */
    public function shutdownDevice() {
        $command = self::CMD_POWEROFF;
        return $this->execCommand($command);
    }
    
    /**
     * Get attendance data
     */
    public function getAttendance() {
        $command = self::CMD_ATTLOG_RRQ;
        $command_string = '';
        
        $session_id = $this->session_id;
        $chksum = 0;
        $reply_id = 65534;
        
        // Prepare data
        $buf = $this->createHeader(self::CMD_PREPARE_DATA, $chksum, $session_id, $reply_id, pack('V', $command));
        socket_write($this->socket, $buf, strlen($buf));
        
        $data = socket_read($this->socket, 1024);
        
        if (!$this->checkValid($data)) {
            return false;
        }
        
        // Get data size
        $size = unpack('V', substr($data, 8, 4))[1];
        
        if ($size <= 0) {
            return array();
        }
        
        // Read data
        $buf = $this->createHeader(self::CMD_DATA, $chksum, $session_id, $reply_id, '');
        socket_write($this->socket, $buf, strlen($buf));
        
        $attendance_data = array();
        $bytes_recv = 0;
        
        while ($bytes_recv < $size) {
            $data = socket_read($this->socket, 1024);
            
            if (!$data) break;
            
            $data = substr($data, 8); // Remove header
            $bytes_recv += strlen($data);
            
            // Parse attendance records (each record is typically 40 bytes)
            for ($i = 0; $i < strlen($data); $i += 40) {
                if ($i + 40 <= strlen($data)) {
                    $record = substr($data, $i, 40);
                    $attendance_data[] = $this->parseAttendanceRecord($record);
                }
            }
        }
        
        // Free data
        $buf = $this->createHeader(self::CMD_FREE_DATA, $chksum, $session_id, $reply_id, '');
        socket_write($this->socket, $buf, strlen($buf));
        
        return $attendance_data;
    }
    
    /**
     * Parse attendance record
     */
    private function parseAttendanceRecord($record) {
        $user_id = unpack('v', substr($record, 0, 2))[1];
        $timestamp = unpack('V', substr($record, 4, 4))[1];
        $verify_type = unpack('C', substr($record, 26, 1))[1];
        $in_out_mode = unpack('C', substr($record, 27, 1))[1];
        
        return array(
            'user_id' => $user_id,
            'timestamp' => date('Y-m-d H:i:s', $timestamp),
            'verify_type' => $verify_type, // 1=fingerprint, 15=face, etc.
            'in_out_mode' => $in_out_mode, // 0=check_in, 1=check_out, etc.
            'raw_data' => bin2hex($record)
        );
    }
    
    /**
     * Clear attendance logs
     */
    public function clearAttendance() {
        $command = self::CMD_CLEAR_ATTLOG;
        return $this->execCommand($command);
    }
    
    /**
     * Write text to LCD
     */
    public function writeLCD($rank, $text) {
        $command = self::CMD_WRITE_LCD;
        $command_string = pack('vC', $rank, 0) . $text;
        return $this->execCommand($command, $command_string);
    }
    
    /**
     * Clear LCD
     */
    public function clearLCD() {
        $command = self::CMD_CLEAR_LCD;
        return $this->execCommand($command);
    }
}
?>
