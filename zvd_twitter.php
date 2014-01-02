<?php
/* --------------------------------- BEGIN PLUGIN SETUP -------------------------------- */

$plugin['name'] = 'zvd_twitter';
$plugin['allow_html_help'] = 1;

$plugin['version'] = '0.6';
$plugin['author'] = 'Adrian Lebar';
$plugin['author_uri'] = 'http://arainoffrogs.com/';
$plugin['description'] = 'Plugin to import tweets as articles.';

$plugin['order'] = 5;
$plugin['type'] = 0;


if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

if (0) {

/* ---------------------------------- END PLUGIN SETUP --------------------------------- */


/* ---------------------------------- BEGIN PLUGIN HELP -------------------------------- */

?>

h1. This plugin imports a twitter feed into the article database

<?php
}
/* ----------------------------------- END PLUGIN HELP --------------------------------- */



/* --------------------------------- BEGIN PLUGIN CODE --------------------------------- */

/* ------------------------------------------------------------------ */
/* get tweets                                                         */
/* ------------------------------------------------------------------ */

function buildBaseString($baseURI, $method, $params) {
    $r = array();
    ksort($params);
    foreach($params as $key=>$value){
        $r[] = "$key=" . rawurlencode($value);
    }
    return $method."&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
}

function buildAuthorizationHeader($oauth) {
    $r = 'Authorization: OAuth ';
    $values = array();
    foreach($oauth as $key=>$value)
        $values[] = "$key=\"" . rawurlencode($value) . "\"";
    $r .= implode(', ', $values);
    return $r;
}

function returnTweet($args){
    $oauth_access_token         = "28117456-ixQ5hLHcr0Yh7rxMmZrDjVZmqRgu5qNvq3xhCO2Mx";
    $oauth_access_token_secret  = "ggcbzR2Zw45IUGdD5X3M8694GBdIyJgkHJT75k3Og48pS";
    $consumer_key               = "fLfM0Bf8WzClmI11qdthcA";
    $consumer_secret            = "8jT9MzVk0MSxqyhyHujW9E4mU9QCFLDFGmWAwA7Yo4";

    $twitter_timeline           = "user_timeline";  //  mentions_timeline / user_timeline / home_timeline / retweets_of_me

    //  create request
        $request = array(
            'screen_name'       => $args[screen_name],
            'count'             => $args[count],
            'page'              => $args[page]
        );

    $oauth = array(
        'oauth_consumer_key'        => $consumer_key,
        'oauth_nonce'               => time(),
        'oauth_signature_method'    => 'HMAC-SHA1',
        'oauth_token'               => $oauth_access_token,
        'oauth_timestamp'           => time(),
        'oauth_version'             => '1.0'
    );

    //  merge request and oauth to one array
        $oauth = array_merge($oauth, $request);

    //  do some magic
        $base_info              = buildBaseString("https://api.twitter.com/1.1/statuses/$twitter_timeline.json", 'GET', $oauth);
        $composite_key          = rawurlencode($consumer_secret) . '&' . rawurlencode($oauth_access_token_secret);
        $oauth_signature            = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
        $oauth['oauth_signature']   = $oauth_signature;

    //  make request
        $header = array(buildAuthorizationHeader($oauth), 'Expect:');
        $options = array( CURLOPT_HTTPHEADER => $header,
                          CURLOPT_HEADER => false,
                          CURLOPT_URL => "https://api.twitter.com/1.1/statuses/$twitter_timeline.json?". http_build_query($request),
                          CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_SSL_VERIFYPEER => false);

        $feed = curl_init();
        curl_setopt_array($feed, $options);
        $json = curl_exec($feed);
        curl_close($feed);

    return json_decode($json, true);
}
/* ------------------------------------------------------------------ */


/* ------------------------------------------------------------------ */
/* make urls in strings into clickable links and twitter              */
/* usernames clickable. format quotes                                 */
/* ------------------------------------------------------------------ */

function processTweet($s) {
    $processed = preg_replace('/https?:\/\/[\w\-\.!~?&+\*\'"(),\/]+/','<a href="$0" class="twlink">$0</a>',$s);
    $processed = preg_replace('/@(\w+)/','<a href="http://twitter.com/$1" class="twuser">@$1</a>',$processed);

    return $processed;
}

/* ------------------------------------------------------------------ */


/* ------------------------------------------------------------------ */
/* strip links from tweet                                             */
/* ------------------------------------------------------------------ */

function stripTweet($s) {
    $processed = preg_replace('/https?:\/\/[\w\-\.!~?&+\*\'"(),\/]+/','',$s);

    return $processed;
}

/* ------------------------------------------------------------------ */



/* ------------------------------------------------------------------ */
/* display tweets                                                     */
/* ------------------------------------------------------------------ */

function zvd_twitter_temporarily_disabled(){

	// sets args to get bulk amounts of tweets
	$twitterArgs = array (
	    'screen_name'       => 'adrianlebar',
	    'count'             => '2',
	    'page'              => '1' // will be deprecated soon.
	);

	// create a sql statement to write into the database

	// set default variables
	$query  = ""; // start query string
	$x = '1';  // set a default counter at 1

	// loop through the tweets
	foreach (returnTweet($twitterArgs) as $val) { 

		$status = "";
		// if it's a retweet or reply, set the status to hidden

		if($val[retweeted-status]) {
			$status = "2"; // (1 = draft, 2 = hidden, 3 = pending, 4 = live, 5 = sticky)
		} else if ($val[text][0] == "@") {
			$status = "2"; // (1 = draft, 2 = hidden, 3 = pending, 4 = live, 5 = sticky)
		} else {
			$status = "1"; // (1 = draft, 2 = hidden, 3 = pending, 4 = live, 5 = sticky)
		}

		// generate query string
		if ($x == 1){
			$query .= "INSERT into ";
			$query .= safe_pfx('textpattern') . " ";
			$query .= "(posted, AuthorID, LastMod, LastModID, Title, Body, Category1, Status, Section, custom_3, custom_4 ,custom_5, uid, feed_time ) ";
			$query .= "VALUES ";
		}

		// add a comma for multiple inserts
		if ($x > 1){
			$query .= ", ";
		}

		$query .= "(";
		/* posted */		$query .= "'" . date( 'Y-m-d H:i:s', strtotime($val[created_at]) ) . "', ";
		/* AuthorID */ 		$query .= "'Adrian Lebar', "; // hard coded to Adrian Lebar
		/* LastMod */		$query .= "'" . date( 'Y-m-d H:i:s', time()) . "',";
		/* LastModID */		$query .= "'Adrian Lebar', "; // hard coded to Adrian Lebar
		/* Title */			$query .= "'" . stripTweet(htmlentities($val[text], ENT_QUOTES,'UTF-8')) . "',";
		/* Body */			$query .= "'" . processTweet(htmlentities($val[text], ENT_QUOTES,'UTF-8')) . "',";
		/* Category 1 */	$query .= "'twitter', "; // matches TWitter Category set in admin prefs
		/* Status */		$query .= "'" . $status . "', "; 
		/* Section */		$query .= "'article', ";
		/* custom_3 */		$query .= "'" . $val[id_str] . "',";
		/* custom_4 */		$query .= "'" . $twitterArgs[screen_name] . "',";
		/*custom_5 */		$query .= "'" . $val[source] . "', ";
		/* uid */			$query .= "'" . md5(uniqid(rand(),true)) . "',"; // uses textpattern standard generation method
		/*feed_time */		$query .= "'" . date( 'Y-m-d', strtotime($val[created_at]) ) . "'";
		$query .= ")";

		$x++;
	}

	// write out to database
	safe_query($query);
	// return $query;
}


function zvd_twitter(){

	// sets args to get bulk amounts of tweets
	$twitterArgs = array (
	    'screen_name'       => 'adrianlebar',
	    'count'             => '2',
	    'page'              => '1' // will be deprecated soon.
	);


	foreach (returnTweet($twitterArgs) as $val) { 

		$status = "";
		// if it's a retweet or reply, set the status to hidden

		if($val[retweeted-status]) {
			$status = "2"; // (1 = draft, 2 = hidden, 3 = pending, 4 = live, 5 = sticky)
		} else if ($val[text][0] == "@") {
			$status = "2"; // (1 = draft, 2 = hidden, 3 = pending, 4 = live, 5 = sticky)
		} else {
			$status = "1"; // (1 = draft, 2 = hidden, 3 = pending, 4 = live, 5 = sticky)
		}

		// generate query string
		if ($x == 1){
			$query .= "INSERT into ";
			$query .= safe_pfx('textpattern') . " ";
			$query .= "(posted, AuthorID, LastMod, LastModID, Title, Body, Category1, Status, Section, custom_3, custom_4 ,custom_5, uid, feed_time ) ";
			$query .= "VALUES ";
		}

		// add a comma for multiple inserts
		if ($x > 1){
			$query .= ", ";
		}

		$query .= "(";
		/* posted */		$query .= "'" . date( 'Y-m-d H:i:s', strtotime($val[created_at]) ) . "', ";
		/* AuthorID */ 		$query .= "'Adrian Lebar', "; // hard coded to Adrian Lebar
		/* LastMod */		$query .= "'" . date( 'Y-m-d H:i:s', time()) . "',";
		/* LastModID */		$query .= "'Adrian Lebar', "; // hard coded to Adrian Lebar
		/* Title */			$query .= "'" . stripTweet(htmlentities($val[text], ENT_QUOTES,'UTF-8')) . "',";
		/* Body */			$query .= "'" . processTweet(htmlentities($val[text], ENT_QUOTES,'UTF-8')) . "',";
		/* Category 1 */	$query .= "'twitter', "; // matches TWitter Category set in admin prefs
		/* Status */		$query .= "'" . $status . "', "; 
		/* Section */		$query .= "'article', ";
		/* custom_3 */		$query .= "'" . $val[id_str] . "',";
		/* custom_4 */		$query .= "'" . $twitterArgs[screen_name] . "',";
		/*custom_5 */		$query .= "'" . $val[source] . "', ";
		/* uid */			$query .= "'" . md5(uniqid(rand(),true)) . "',"; // uses textpattern standard generation method
		/*feed_time */		$query .= "'" . date( 'Y-m-d', strtotime($val[created_at]) ) . "'";
		$query .= ")";

		$x++;
	}

	$table = safe_pfx('textpattern');
	//$sql = "SELECT * FROM " . safe_pfx('textpattern') . " WHERE Category1='twitter' ORDER BY Posted DESC LIMIT 1";
	$sql = safe_row('*', $table, 'Category1="twitter" ORDER BY Posted DESC');
	$row = safe_query($sql);
	//$lastTweetID = $row[0];



	// write out to database
	// safe_query($query);
	return $sql;

}
/* ------------------------------------------------------------------ */

/* ---------------------------------- END PLUGIN CODE ---------------------------------- */
?>
