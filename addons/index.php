<?php
//	require_once(realpath(dirname(__DIR__) . "/private/class/BoardManager.php"));
//	require_once dirname(__DIR__) . "/private/class/BoardManager.php";

	$_PAGETITLE = "Glass | Add-Ons";

	include(realpath(dirname(__DIR__) . "/private/header.php"));
	include(realpath(dirname(__DIR__) . "/private/navigationbar.php"));
?>
<div class="maincontainer">
	<?php include(realpath(dirname(__DIR__) . "/private/searchbar.php")); ?>

	<table class="addontable">
	<tbody>
	<?php
		$boardIndex = include(realpath(dirname(__DIR__) . "/private/json/getBoardIndex.php"));

		foreach($boardIndex as $subCategory => $boards) {
			echo("<tr class=\"addonheader\"><td colspan=\"3\"><b>" . htmlspecialchars($subCategory) . "</b></td></tr>");

			foreach($boards as $board) {
				echo("<tr><td><image src=\"http://blocklandglass.com/icon/icons32/" . $board->icon . ".png\" /></td>");
				echo("<td><a href=\"board.php?id=" . $board->id . "\">   " . htmlspecialchars($board->name) . "</a></td>");
				echo("<td>" . $board->count . "</td></tr>");
			}
		}

		//This got kind of messy when I edited it to reflect boardManager changes
		//We should probably redo part of it anyway to reflect tags
		//$boards = BoardManager::getAllBoards();
		//usort($boards, function($a, $b) {
		//	return strcmp($a->getName(), $b->getName());
		//});
		//$subcat = array();
		//foreach($boards as $board) {
		//	$subcat[$board->getSubCategory()][] = $board;
		//}
		//foreach($subcat as $subName=>$sub) {
		//	echo "<tr class=\"addonheader\">
		//		<td colspan=\"3\"><b>" . htmlspecialchars($subName) . "</b></td>
		//	</tr>";
		//	foreach($sub as $board) {
		//		echo "<tr><td><image src=\"http://blocklandglass.com/icon/icons32/" . $board->getIcon() . ".png\" /></td>";
		//		echo "<td><a href=\"board.php?id=" . $board->getID() . "\">   " . htmlspecialchars($board->getName()) . "</a></td>";
		//		echo "<td>" . $board->getCount() . "</td></tr>";
		//	}
		//}
		?>
		<tr class="addonheader">
			<td colspan="3"></td>
		</tr>
	</tbody>
	</table>
</div>
<?php include(realpath(dirname(__DIR__) . "/private/footer.php")); ?>
