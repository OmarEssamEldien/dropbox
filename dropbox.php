<?php

Class dropbox {

    private $credentials_file;
    private $credentials;

    function __construct() {
        $this->credentials_file = __DIR__."/apis/dropbox.ini";
        $this->credentials = parse_ini_file($this->credentials_file);
        if((time() - 3600 > $this->credentials['last_refresh'])) { // Time Passed Since Last Refresh Token
            $this->refresh_token();
        }
    }
    
    public function update_credentials(array $data) {

        $dropbox = $this->credentials;
        $app_key = (@$data['app_key'] === NULL) ? $dropbox["app_key"] : $data["app_key"];
        $app_secret = (@$data['app_secret'] === NULL) ? $dropbox["app_secret"] : $data["app_secret"];
        $access_code = (@$data['access_code'] === NULL) ? $dropbox["access_code"] : $data["access_code"];
        $access_token = (@$data['access_token'] === NULL) ? $dropbox["access_token"] : $data["access_token"];
        $refresh_token = (@$data['refresh_token'] === NULL) ? $dropbox["refresh_token"] : $data["refresh_token"];
        $last_refresh = (@$data['last_refresh'] === NULL) ? time() : $data["last_refresh"];

        $dropbox =  'app_key="'.$app_key.'"'."\r\n"
                    .'app_secret="'.$app_secret.'"'."\r\n"
                    .'access_code="'.$access_code.'"'."\r\n"
                    .'access_token="'.$access_token.'"'."\r\n"
                    .'refresh_token="'.$refresh_token.'"'."\r\n"
                    .'last_refresh="'.$last_refresh.'"';

        
        file_put_contents($this->credentials_file, $dropbox);
        
        $this->credentials = parse_ini_file($this->credentials_file);

        return TRUE;
    }

    public function is_connected() {
        // $this->refresh_token()
        $list = $this->list_files();
        return is_array($list) && isset($list['cursor']) && !empty($this->credentials['refresh_token']) && !empty($this->credentials['access_code']);
    }

    public function access_code_url() {
        $this->refresh_token();
        $dropbox = $this->credentials;
        $app_key = $dropbox["app_key"];
        return "https://www.dropbox.com/oauth2/authorize?client_id=$app_key&response_type=code&token_access_type=offline";
    }

    public function refresh_token($generate_refresh_token = FALSE) {
        $dropbox = $this->credentials;
        $app_key = $dropbox["app_key"];
        $app_secret = $dropbox["app_secret"];
        $access_code = $dropbox["access_code"];
        $access_token = $dropbox["access_token"];
        $refresh_token = $generate_refresh_token ? "" : $dropbox["refresh_token"];

        if(empty($refresh_token)) {
            $ch = curl_init();
        
            curl_setopt($ch, CURLOPT_URL, 'https://api.dropbox.com/oauth2/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "code=$access_code&grant_type=authorization_code");
            curl_setopt($ch, CURLOPT_USERPWD, $app_key . ':' . $app_secret);
            
            $headers = array();
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            $result = curl_exec($ch);
            
            curl_close($ch);
            $result = json_decode($result, TRUE);
            if(!empty($result["error"])) {
                // $url = "https://www.dropbox.com/oauth2/authorize?client_id=$app_key&response_type=code&token_access_type=offline";
                // echo "Get New Dropbox Access Code: <a href='$url' target=_blank>$url</a>";
                
                $this->update_credentials(['access_code'=>'', 'last_refresh'=>0]);

                return FALSE;
            }
            if($result && !empty($result['refresh_token'])) {
                $access_token = $result['access_token'];
                $refresh_token = $result['refresh_token'];

                $this->update_credentials(['access_token'=>$access_token, 'refresh_token'=>$refresh_token, 'last_refresh'=> time()]);
            }
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.dropbox.com/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "refresh_token=$refresh_token&grant_type=refresh_token");
        curl_setopt($ch, CURLOPT_USERPWD, $app_key . ':' . $app_secret);
        
        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        
        curl_close($ch);
        $result = json_decode($result, TRUE);

        if($result && !empty($result['access_token'])) {
            $access_token = $result['access_token'];

            $this->update_credentials(['access_token'=>$access_token, 'last_refresh'=>time()]);
            
            return TRUE;
        }
        return FALSE;
    }

    public function list_files() {
        
        $dropbox = $this->credentials;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["path"=>""]));

        $headers = array();
        $headers[] = 'Authorization: Bearer '.$dropbox['access_token'];
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result, TRUE);
        
        return $result;
    }

    public function upload(string $filepath, string $filename) {

        $dropbox = $this->credentials;
        
        $api_url = 'https://content.dropboxapi.com/2/files/upload_session/start'; //dropbox api url
        $headers = array('Authorization: Bearer '. $dropbox['access_token'],
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: '. json_encode( [ "close" => false ] )
        );

        $ch = curl_init($api_url);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        
        $response = json_decode($response, TRUE);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if(empty($response['session_id'])) {
            $response = is_array($response) ? $response : [];
            $response['error'] = "Failed to Start Upload Session";
            return $response;
        }
        
        $session_id = $response['session_id'];

        // Append File to Session

        $path = $filepath;
        $filesize = filesize($path);
        $fp = fopen($path, 'rb');
        $offset = 0;
        while(! feof($fp)) {

            $chunk_size = 150 * 1000 * 1000; // 150MB

            $contents = fread($fp, $chunk_size); // 150MB
            
            $api_url = 'https://content.dropboxapi.com/2/files/upload_session/append_v2'; //dropbox api url
            $headers = array('Authorization: Bearer '. $dropbox['access_token'],
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: '. json_encode( [ "close" => false, "cursor"=> ["offset"=>$offset, "session_id"=>$session_id] ] )
            );

            $ch = curl_init($api_url);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $contents);
            // curl_setopt($ch, CURLOPT_POSTFIELDS, fread($fp, $filesize));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //        curl_setopt($ch, CURLOPT_VERBOSE, 1); // debug
    
            $response = curl_exec($ch);
            $response = json_decode($response, TRUE);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
            curl_close($ch);
    
            if(!empty($response)) {
                return $response;
            }

            $offset+=$chunk_size;
        }
        

        // Finish File Upload Session
        $api_url = 'https://content.dropboxapi.com/2/files/upload_session/finish'; //dropbox api url
        $headers = array('Authorization: Bearer '. $dropbox['access_token'],
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: '. json_encode( 
                [
                "commit"=>[
                    "path"=> '/'. basename($filename),
                    "mode" => "add",
                    "autorename" => false,
                    "mute" => false
                ], 
                "cursor"=> ["offset"=>$filesize, "session_id"=>$session_id] 
            ] )
        );

        $ch = curl_init($api_url);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        
        $response = json_decode($response, TRUE);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return ['http_code'=>$http_code, 'response'=>$response, 'id'=>@$response['id'], 'error'=>@$response['error_summary']];

        // $api_url = 'https://content.dropboxapi.com/2/files/upload'; //dropbox api url
        // $headers = array('Authorization: Bearer '. $dropbox['access_token'],
        //     'Content-Type: application/octet-stream',
        //     'Dropbox-API-Arg: '.
        //     json_encode(
        //         array(
        //             "path"=> '/'. basename($filename),
        //             "mode" => "add",
        //             "autorename" => false,
        //             "mute" => false
        //         )
        //     )

        // );

        // $ch = curl_init($api_url);

        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // curl_setopt($ch, CURLOPT_POST, true);

        // $path = $filepath;
        // $fp = fopen($path, 'rb');
        // $filesize = filesize($path);

        // curl_setopt($ch, CURLOPT_POSTFIELDS, fread($fp, $filesize));
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // //        curl_setopt($ch, CURLOPT_VERBOSE, 1); // debug

        // $response = curl_exec($ch);
        // $response = json_decode($response, TRUE);
        // $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // curl_close($ch);

        // return ['http_code'=>$http_code, 'response'=>$response, 'id'=>@$response['id'], 'error'=>@$response['error_summary']];
    }

    public function download(string $file_id, string $file_path) {
        $dropbox = $this->credentials;
    
        set_time_limit(10*60); // 10 Mins
        ini_set('memory_limit', -1);

        $params = ['path'=>$file_id];
        $params = json_encode($params);
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://content.dropboxapi.com/2/files/download');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        $headers = array();
        $headers[] = 'Authorization: Bearer '.$dropbox['access_token'];
        $headers[] = 'Content-Type: application/octet-stream';
        $headers[] = 'Dropbox-Api-Arg: '.$params;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        $response = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        $header = explode("\r\n", $header);
        $file_found = FALSE;
        foreach($header as $_header) {
            if(strtolower(explode(":", $_header)[0]) == "dropbox-api-result") {
                $file_data = @json_decode(explode(": ", $_header, 2)[1], TRUE);
                if(!empty($file_data))
                    $file_found = TRUE;
                break;
            }
        }
        if($file_found && $file_data) {
            $ext = pathinfo($file_data['name'], PATHINFO_EXTENSION);
            if(file_put_contents($file_path, $body)) {
                return TRUE;
            }
        } else {
            // echo "File Not Found";
            return FALSE;
        }
        if (curl_errno($ch)) {
            // echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);
        return FALSE;
    }
    
    public function delete(string $file_id) {
        $dropbox = $this->credentials;
    
        $params = ['entries'=> [ ['path'=> $file_id] ] ];
        $params = json_encode($params);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.dropboxapi.com/2/files/delete_batch');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        $headers = array();
        $headers[] = 'Authorization: Bearer '.$dropbox['access_token'];
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, TRUE);

        return !empty($json) && !empty($json['async_job_id']);
    }
}

// header("Content-type: text/plain");

// $dropbox = new dropbox();
// $upload = $dropbox->upload(__DIR__."/photo3.jpg", "new_photo2.jpg");
// $ext = "jpg";
// $filename = uniqid(time()).".".$ext;
// $download = $dropbox->download("id:g8S1zEsLjTAAAAAAAAAADA", __DIR__."/../uploads/temp/$filename");
// $delete = $dropbox->delete("id:g8S1zEsLjTAAAAAAAAAACw");
// echo "<pre>";
// // print_r($delete);
// print_r($dropbox->list_files());
// echo "</pre>";
