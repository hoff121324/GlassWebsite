<?php
require_once(realpath(dirname(__FILE__) . "/private/header.php"));
require_once(realpath(dirname(__FILE__) . "/private/navigationbar.php"));
?>

<div class="bigheadcontainer">
	<h1>Blockland Glass</h1>
	<h2>A service for the community, by the community</h2>
	<a href="dl.php" class="btn blue"><b>Download</b></a><br />
	<a href="builds" class="btn green" style="width: 150px">Builds</a>
	<a href="addons" class="btn yellow" style="width: 150px">Add-Ons</a><br /><br />
</div>
<div class="maincontainer">
	<p>
		<h3>What's Glass?</h3>
		Blockland Glass is a service made for <a href="http://blockland.us" />Blockland</a> to help expand and cultivate the community. Currently, Glass acts as a mod management service; however, we plan to expand in the future.
	</p>
	<br />
	<p>
		<h3>By the community?</h3>
		Glass is intended to be a group project. Although the bulk of the work has been fronted individually, we're striving to move to an open-source site, allowing for Glass to be a truely community made project.
	</p>
	<br />
	<p>
		<h3>Live Stats</h3>
		Right now, theres <b>34</b> Blockland Server onlines with <b>407</b> users. Of those, <a href="stats/users.php">36</a> users are running Glass! Glass has <a href="stats/usage.php">253</a> active users, accumulating a total of <a href="stats/usage.php">15000</a> downloads.
	</p>
	<br />
	<p>
		<h3>Want to get involved?</h3>
		As you can probably tell, this site points straight to the old one. The old one was developed independently and is very distasteful. In an attempt to modernize the site, this new one is entirely open source. Please, contribute on <a href="http://github.com/BlocklandGlass/GlassWebsite/">GitHub</a> and check out the <a href="http://forum.blockland.us/index.php?topic=282486.0">Glass Development Topic</a> over on the Blockland forums!
	</p>
</div>

<?php require_once(realpath(dirname(__FILE__) . "/private/footer.php")); ?>
