<?php
	$_PAGETITLE = "Glass | Review List";

	include(realpath(dirname(__DIR__) . "/../private/header.php"));
	include(realpath(dirname(__DIR__) . "/../private/navigationbar.php"));
	require_once(realpath(dirname(__DIR__) . "/../private/class/AddonManager.php"));
	require_once(realpath(dirname(__DIR__) . "/../private/class/UserManager.php"));
?>
<div class="maincontainer">
  <table style="width: 100%">
    <thead>
      <tr><th>Add-On</th><th>Author</th><th>Uploaded</th></tr>
    </thead>
    <tbody>
    <?php
      $list = AddonManager::getUnapproved();
      foreach($list as $addon) {
				$manager = UserManager::getFromBLID($addon->getManagerBLID());
				if(is_object($manager)) {
					$name = $manager->getName();
				}	else {
					$name = $addon->getManagerBLID();
				}
        echo "<tr>";
        echo "<td><a href=\"inspect.php?id=" . $addon->getId() . "\">" . $addon->getName() . "</a></td>";
        echo "<td>" . $name . "</td>";
        echo "<td>" . date("D, g:i a", strtotime($addon->getUploadDate())) . "</td>";
        echo "</tr>";
      }
    ?>
    </tbody>
  </table>
</div>

<?php
	//TO DO:
	//add script to bottom of page to prevent refresh on search

	include(realpath(dirname(__DIR__) . "/../private/footer.php")); ?>
