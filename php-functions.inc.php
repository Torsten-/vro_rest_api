<?php

$vco_hostname = "your vRO Hostname";

/*
 * Call vRO with URL
 * Just called from other functions, not called single
 */
function vco_call_rest($url,$data = false){
  global $vco_hostname;
  $curl = curl_init();

  if($data){ // If data is providet change to POST instead of GET
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
  }

  // Provide User & PW
  curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  curl_setopt($curl, CURLOPT_USERPWD, $_SERVER["PHP_AUTH_USER"].":".$_SERVER["PHP_AUTH_PW"]);

  // deactivate SSL Check
  curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

  // build the complete URL
  if(!preg_match("/^http/",$url)) $url = "https://".$vco_hostname.":8281/vco/api/".$url;
  curl_setopt($curl, CURLOPT_URL, $url);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

  // Use JSON instead of XML
  curl_setopt($curl, CURLOPT_HTTPHEADER, array("Accept: application/json","Content-Type: application/json"));

  // Give me the headers too
  curl_setopt($curl, CURLOPT_HEADER, 1);

  $response = curl_exec($curl);
  $info = curl_getinfo($curl);
  curl_close($curl);
  $header = substr($response, 0, $info['header_size']);
  $head = array();
  foreach(explode("\n",str_replace("\r","",$header)) as $line){
    if(strstr($line,": ")){
      $cols = explode(": ",$line);
      $head[$cols[0]] = $cols[1];
    }
  }
  $body = substr($response, $info['header_size']);

  if($body) $body = json_decode($body,true);
  else $body = false;

  return array("head" => $head, "http_code" => $info["http_code"], "body" => $body);
}

/*
 * Start a vRO Workflow
 *
 * Input:
 *   wfid       - Workflow-ID (e.g. "59f17e41-e965-4c25-a15a-72221e8cde5d")
 *   parameter  - Array with parameters:
 *                $parameter = array(
 *                  array("name" => "inHost", "type" => "VC:HostSystem",     "value" => "your.vcenter.name/host-123"),
 *                  array("name" => "inVM",   "type" => "VC:VirtualMachine", "value" => "your.vcenter.name/vm-12345")
 *                );
 *
 * Output: (Array with the following key=>value pairs)
 *    error     - true/false
 *    meldung   - exception text if error = true
 *    output    - array with output-parameters (only if error = false)
 *                output-parameter-name => value
 */
function vco_run_workflow($wfid,$parameter){
  // Build parameter array
  $param = array("parameters" => array());
  foreach($parameter as $p){
    switch($p["type"]){
      case "boolean":  $value = array("boolean" => array("value" => $p["value"]));                        break;
      case "string":   $value = array("string" => array("value" => $p["value"]));                         break;
      case "number":   $value = array("number" => array("value" => $p["value"]));                         break;
      case "Text":     $value = array("string" => array("value" => $p["value"]));                         break;
      default:         $value = array("sdk-object" => array("type" => $p["type"], "id" => $p["value"]));  break;
    }

    $param["parameters"][] = array(
      "value" => $value,
      "type" => $p["type"],
      "name" => $p["name"],
      "scope" => "local"
    );
  }

  // Run workflow
  $wf = vco_call_rest("workflows/".$wfid."/executions",json_encode($param));
  if($wf["http_code"] != 202){
    echo "<hr>";
    echo "<b>Debug-Output:</b><br>";
    echo "<pre>"; print_r($wf); echo "</pre>";
    echo "<hr>";
    return array(
      "error"   => true,
      "meldung" => "ERROR: Workflow was not able to start."
    );
  }

  // Observe workflow run until it ends
  $status = "running";
  while($status == "running" || $status == ""){
    sleep(1);
    $state = vco_call_rest($wf["head"]["Location"]."state");
    $status = $state["body"]["value"];
  }

  // Get the results of the workflow run
  $result = vco_call_rest($wf["head"]["Location"]);

  // Parse the results
  if($status != "completed"){
    return array(
      "error"   => true,
      "meldung" => "ERROR: Workflow could not be ended successfully (Status: ".$status."): ".$result["body"]["content-exception"]
    );
  }else{
    return array(
      "error"   => false,
      "meldung" => "Workflow ended successfully.",
      "output"  => vco_format_output($result)
    );
  }
}

/*
 * Format the vRO output parameters
 *
 * Input:
 *   result     - Return of the function vco_call_rest();
 *
 * Output:
 *   Array with Output-Parameters
 *   output-parameter-name => value
 */
function vco_format_output($result){
  $outputs = array();
  foreach($result["body"]["output-parameters"] as $param){
    $key = key($param["value"]);
    if($key == "array"){
      $value = array();
      foreach($param["value"]["array"]["elements"] as $element){
        switch(key($element)){
          case "boolean":     $value[] = $element["string"]["value"];  break;
          case "string":      $value[] = $element["string"]["value"];  break;
          case "number":      $value[] = $element["string"]["value"];  break;
          case "Text":        $value[] = $element["string"]["value"];  break;
          case "sdk-object":  $value[] = $element["sdk-object"];       break;
          default:            $value[] = $element;                     break;
        }
      }
    }else{
      switch($key){
        case "boolean":     $value = $param["value"]["string"]["value"];  break;
        case "string":      $value = $param["value"]["string"]["value"];  break;
        case "number":      $value = $param["value"]["string"]["value"];  break;
        case "Text":        $value = $param["value"]["string"]["value"];  break;
        case "sdk-object":  $value = $param["value"]["sdk-object"];       break;
        default:            $value = $param["value"];                     break;
      }
    }
    $outputs[$param["name"]] = $value;
  }
  return $outputs;
}
?>
