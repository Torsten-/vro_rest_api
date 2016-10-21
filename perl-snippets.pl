use LWP;
use XML::Simple;

my $vco = "<vRO_Hostname>:8281";
my $xml = XML::Simple->new();
my $browser = LWP::UserAgent->new;
my $vco_url = "https://".$vco."/vco/api/";
$browser->default_header("Accept" => "application/xml");
$browser->default_header("Content-Type" => "application/xml");

$browser->credentials($vco,"vCO Authentication",$user,$pw); // Provide User and PW

 
 ###################
 # Search MoId
 ###################
 my $url = $vco_url."catalog/VC/HostSystem/?conditions=name=".$host;
 my $response = $browser->get($url);

 if(!$response->is_success){
   print "ERROR: ".$response->status_line."\n";
   exit 1;
 }

 $content = $xml->XMLin($response->content);
 if($content->{'total'} < 1){
   print "ERROR: $host not found.\n";
   exit 1;
 }elsif($content->{'total'} > 1){
   print "ERROR: searching for $host gives more than one result.\n";
   exit 1;
 }else{
   $moid = $content->{'link'}->{'attributes'}->{'attribute'}->{'dunesId'}->{'value'}); // your.vcenter.name/host-2345
 }

###################
# Do a POST
###################
my $url = $vco_url."workflows/".$workflowId."/executions/";
my $response = $browser->post($url,'content-type' => 'application/xml', 'Content' => $parameter);

if(!$response->is_success){
  print "ERROR: ".$response->status_line."\n";
  exit 1;
}

###################
# Wait for Workflow to end
###################
my $wfRunUrl = $response->{_headers}->{location};
do{
  sleep 1;
  $response = $browser->get($wfRunUrl);
  if(!$response->is_success){
    print "ERROR: ".$response->status_line."\n";
    exit 1;
  }
  $content = $xml->XMLin($response->content);
}while($content->{state} eq "running");

if($content->{state} ne "completed"){
  print $content->{'content-exception'}."\n";
  exit 1;
}
print "Workflow successfull ended.\n";
