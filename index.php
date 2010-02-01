<?php

define(TRSS_VERSION, '0.1.0');

# Some pieces of content will have to be parsed into HTML where we have to add
# HTML strucutre (e.g. around conversations)
require_once("markdown.php");

# Check for valid input
$tumblr = isset($_REQUEST['tumblr']) && !empty($_REQUEST['tumblr']) ? $_REQUEST['tumblr'] : '';

if(empty($tumblr)): ?>
<!DOCTYPE html>
<html lang="en">
	<head>
	    <meta charset="utf-8">
		<title>Tumblfeed: Better Feeds for Tumblr Blogs</title>
		<!-- <link rel="stylesheet" href="t2w.css" type="text/css"> -->
	</head>
	<body>
		<h1>Tumblfeed</h1>
		<dl>
			<dt>Version</dt>
			<dd><?php echo TRSS_VERSION ?></dd>
			<dt>Author</dt>
			<dd class="vcard">
			    This version by
			    <a class="fn url" href="http://benward.me">Ben Ward</a>
			</dd>
			<dt>License</dt>
			<dd><a rel="license" href="http://www.gnu.org/licenses/gpl.html">GPL v3</a></dd>
			<dt>Source Code</dt>
			<dd><a href="http://github.com/benward/tumblfeed">github.com/benward/tumblfeed</a></dd>
		</dl>
		<p>This tool reads posts from a Tumblr blog you specify, and outputs
		    an Atom feed of the content with full, rich HTML mark-up. Native RSS
		    feeds from Tumblr are second-class citizens, perhaps a negative
		    incentive to use the Tumblr Dashboard for everything. Tumblr RSS is
		    sparse, and misses simple mark-up like <code>&lt;blockquote></code>
		    on quotes, making content harder to read in feed readers.</p>

		<form method="GET" action="">
		<fieldset>
		    <legend>Tumblr Blog</legend>
		    <label for="tumblr-url">Tumblr Blog URL</label>
		    <samp>http://</samp>
		    <input type="text" id="tumblr-url" name="tumblr" size="40">
		    <samp>/rss</samp>

		</fieldset>
    	<fieldset>
    	    <legend>Open Wide…</legend>
		    <input type="submit" value="Feed Me">
		</fieldset>
		</form>

		<h2>Bookmarklet</h2>
        <p>If you're on a Tumblr Blog and want to subscribe to its feed, run
            this bookmarklet first. It will replace the Tumblr RSS links with
            Tumblfeed, so that when you hit subscribe, it's the Tumblfeed that
            will be added, not the native Tumblr version.</p>
        <p><code>TODO: Write the bookmarklet.</code></p>

        <h2>GreaseKit Script</h2>
        <p>Automatically convert Tumblr feed URLs to use Tumblfeed.</p>
        <p><code>TODO: Write the script.</code></p>
	</body>
</html>
<?php
  # If we output the form, end now:
  exit();
endif;

# Didn't output the form. So, process the input:

$i = 0;

$posts = array();
$feed = '';

# OK. Query the Tumblr API for the posts and get them all in 50-post batches:
try {
    # Read the most recent 20 posts from the Tumblr API:
	$url = 'http://'.$tumblr. '/api/read?start=0&num=20&filter=none';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, TL_USERAGENT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); # Follow 301/302
    curl_setopt($ch, CURLOPT_POST, 0);
    curl_setopt($ch, CURLOPT_URL, $url);
    $data = curl_exec($ch);
    $curl_info = curl_getinfo($ch);

    if(200 != $curl_info['http_code']) {
        # Some kind of error. Just debug output for now:
        header("500", true, 500);
        echo "<title>Tumblr2Wordpress Error</title>\n";
        echo "<h1>Tumblr API Request Failed</h1>\n";
        echo "<p>Requesting a set of posts from the Tumblr API failed.
        See below for debugging output.</p>\n";
        echo "<dl>\n";
        echo "\t<dt>Error was</dt>\n\t<dd><code>" . $status_code . "</code></dd>\n";
        echo "\t<dt>Request URL</dt>\n\t<dd><code>" . $url . "</code></dd>\n";
        echo "\t<dt>Posts fetched (so far)</dt>\n\t<dd><code>" . $i . "</code></dd>\n";
        echo "\t<dt>Last API response</dt>\n\t<dd><pre><code>" . htmlspecialchars($data) . "</code></pre></dd>\n";
        echo "\t<dt>Curl Info</dt>\n\t<dd><pre><code>";
        print_r($curl_info);
        echo "</code></pre></dd>\n";
        echo "</dl>";
        die();
    }

    curl_close($ch);

	$feed = new SimpleXMLElement($data);
	$posts = array_merge($posts, $feed->xpath('posts//post'));
}
catch(Exception $e) {
    header("500", true, 500);
    echo "<title>Tumblr2Wordpress Error</title>\n";
    echo "<h1>Error fetching Tumblr posts</h1>\n";
    echo "<p>Something went wrong whilst collecting posts from the Tumblr API,
    see below for debugging output.</p>\n";
    echo "<dl>\n";
    echo "\t<dt>Error was</dt>\n\t<dd><code>" . $e->getMessage() . "</code></dd>\n";
    echo "\t<dt>Request URL</dt>\n\t<dd><code>" . $url . "</code></dd>\n";
    echo "\t<dt>Posts fetched (so far)</dt>\n\t<dd><code>" . $i . "</code></dd>\n";
    echo "\t<dt>Last API response</dt>\n\t<dd><pre><code>" . htmlspecialchars($data) . "</code></pre></dd>\n";
    echo "</dl>";
    die();
}

function removeWeirdChars($str) {
    return trim(preg_replace('{(-)\1+}','$1',preg_replace('/[^a-zA-Z0-9-]/', '', str_replace(' ','-',strtolower(strip_tags($str))))),'-');
}

function getTags($post) {
    global $tumblr;

	if($post->attributes()->type) {
		echo "        <category scheme=\"$tumblr\" term=\"" .$post->attributes()->type . "\"/>\n";
	}
	if($post->tag) {
		foreach($post->tag as $tag) {
			echo "        <category scheme=\"$tumblr\" term=\"" . removeWeirdChars($tag) . "\"/>\n";
		}
	}
}

# Try to extract a sane, single line blog title from input text, and
# (optionally) remove it from the entry body to avoid duplication.
function formatEntryTitle(&$text, $strip=true) {
    $lines = explode("\n", $text);
    $block_count = 0; # How far into the entry are we?
    for($i=0; $l = $lines[$i]; $i++) {

        if(empty($l)) {
            # Ignoring emptry lines
            continue;
        }
        elseif(preg_match('/^\s*(#+|<[hH][1-6]>).*$/', $l, $match)) {
            # Matches a heading in Markdown or HTML

            # Now we need to see if the title embeds any links. If it does,
            # we want to strip out the link mark-up…

            # Run markdown:
            $l = Markdown($l);

            # Crudely check for <a>
            $contains_link = !(false === stripos('<a', $l));

            # In the final return, strip not-inline HTML tags.
            return str_replace('\n', '', strip_tags($l));
            #'<abbr><acronym><i><b><strong><em><code><kbd><samp><span><q>
            # <cite><dfn><ins><del><mark><meter><rp><rt><ruby><sub><sup>
            # <time><var>'
        }
        else {
            $block_count++;
        }

        if($block_count > 2) {
            # Too far into the post. Give up.
            break;
        }
    }
    return '';
}

# Check if a media URL is hosted on Tumblr's server (cannot be hotlinked)
function isTumblrHostedMedia($media_url) {
	return (false !== stripos($media_url, 'tumblr.com'));
}

header('content-type: application/atom+xml');
?>
<?php echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n"; ?>
<!-- generator="Tumblr2WordPress/<?php echo T2W_VERSION ?>" created="<?php echo date("Y-m-d H:i") ?>"-->
<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="en">
    <?php if(!empty($feed->tumblelog->attributes()->cname)) {
        $blog_url = $feed->tumblelog->attributes()->cname;
    }
    else {
        $blog_url = $feed->tumblelog->attributes()->name . ".tumblr.com";
    }?>

    <title><?php echo $feed->tumblelog->attributes()->title ?></title>
	<link rel="alternate" type="text/html" href="http://<?php echo $blog_url ?>"/>
    <!-- <link rel="self" type="application/atom+xml" href="http://benward.me/feed/atom" /> -->
    <link rel="hub" href="http://tumblr.superfeedr.com/"/>
    <id>http://<?php echo $blog_url ?>/rss</id>
	<updated><?php echo date("c", (double)$posts[0]->attributes()->{'unix-timestamp'}) ?></updated>
	<generator uri="http://github.com/benward/tumblfeed">Tumblfeed/<?php echo TRSS_VERSION . ' (' . $_SERVER['HTTP_HOST'] . ')' ?></generator>
<?php
    ob_start();
	foreach($posts as $post) {
?>
	<entry>
<?php
        # Shared Output:
?>
		<link rel="alternate" type="text/html" href="<?php echo $post->attributes()->url ?>" />
		<id><?php echo $post->attributes()->url ?></id>
		<updated><?php echo date("c", (double)$post->attributes()->{'unix-timestamp'}) ?></updated>
		<author>
		    <name><![CDATA[<?php echo $feed->tumblelog->attributes()->name ?>]]></name>
		</author>
<?php getTags($post) ?>
<?php
        // Post Specific Elements:
		switch($post->attributes()->type) {
			case "regular": ?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars($post->{'regular-title'}) ?>]]></title>
		<content type="html"><![CDATA[<?php echo Markdown($post->{'regular-body'}) ?>]]></content>
<?php		break;

			case "photo":
			$post_content = $post->{'photo-caption'};
			?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars(formatEntryTitle(&$post_content)) ?>]]></title>
		<content type="html"><![CDATA[<img src="<?php echo $post->{'photo-url'} ?>" alt="">

		<?php echo Markdown($post_content) ?>]]></content>
<?php       break;

			case "quote":
			$post_content = $post->{'quote-source'};

			# Mark-up the quote:
            $quote_text = "<blockquote>" . Markdown($post->{'quote-text'}) . "</blockquote>";
		?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars(formatEntryTitle(&$post_content)) ?>]]></title>
		<content type="html"><![CDATA[<?php echo $quote_text ?>
		<?php echo Markdown($post_content) ?>]]></content>
<?php
			break;

			case "link": ?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars(strip_tags($post->{'link-text'})) ?>]]></title>
		<content type="html"><![CDATA[<p><strong>Link:</strong> <a href="<?php echo $post->{'link-url'} ?>"><?php echo $post->{'link-text'} ?></a></p>
		<?php echo Markdown($post->{'link-description'}) ?>]]></content>
<?php
			break;

		    case "conversation": ?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars(strip_tags($post->{'conversation-title'})) ?>]]></title>
		<content type="html"><![CDATA[<?php
		    foreach($post->{'conversation-line'} as $line) {
		        ?><cite><?php
		            echo preg_replace('/(<\/?p>|\n)/', '', Markdown($line->attributes()->label));
		        ?></cite>: <q><?php
		            echo preg_replace('/(<\/?p>|\n)/', '', Markdown($line));
		        ?></q><br>
		    <?php } ?>]]></content>
<?php
			break;

			case "video":
			$post_content = $post->{'video-caption'};
		?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars(formatEntryTitle(&$post_content)) ?>]]></title>
		<content type="html"><![CDATA[<?php
	        echo $post->{'video-player'};
            echo Markdown($post_content);
        ?>]]></content>
<?php
			break;
			case "audio":
			$post_content = $post->{'audio-caption'};
			$audio_file = preg_match('/audio_file=([\S\s]*?)(&|")/', $post->{'audio-player'}, $matches);
			echo "<!-- {$matches[1]} -->\n";
		?>
	 	<title type="html"><![CDATA[<?php echo htmlspecialchars(formatEntryTitle(&$post_content)) ?>]]></title>
		<content type="html"><![CDATA[<?php if(isTumblrHostedMedia($matches[1])) {
		    ?><p><strong>Audio</strong>: <a href="<?php echo $post->attributes()->url ?>">Playback audio on Tumblr</a></p><?php
		}
		else {
		    ?><p><audio controls src="<?php echo $matches[1] ?>"><a href="<?php echo $matches[1]; ?>">Download Audio</a></audio></p><?php
		} ?>
		<?php echo Markdown($post_content); ?>]]></content>
<?php
			break;
		}
?>
	</entry>
<?php
	}
	$out = ob_get_contents();
	ob_end_clean();
	echo $out;
?>
</feed>