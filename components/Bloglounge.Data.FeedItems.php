<?php

	class FeedItem {
		
		function get($itemId, $field) { // return as single value
			global $database, $db;
			if (list($value) = $db->pick('SELECT '.$field.' FROM '.$database['prefix'].'FeedItems WHERE id='.$itemId))
				return $value;
			return false;
		}

		function gets($itemId, $fields) { // return as array
			global $database, $db;
			if (empty($itemId) || !preg_match("/^[0-9]+$/", $itemId)) {
				return false;
			}
			
			$result = array();
			if ($db->query('SELECT '.$fields.' FROM '.$database['prefix'].'FeedItems WHERE id='.$itemId)) {
				$data = $db->fetchRow();
				foreach ($data as $row) {
					array_push($result, $row);
				}
				$db->free();
			}
			return $result;
		}

		function getAll($itemId) {
			global $database, $db;
			$db->query('SELECT * FROM '.$database['prefix'].'FeedItems WHERE id='.$itemId);
			return $db->fetchArray();
		}

		function getIdByURL($url) {
			global $database, $db;
			if (!isset($url)) return false;

			$id = false;
			list($id) = $db->pick('SELECT id FROM '.$database['prefix'].'FeedItems WHERE permalink="'.$url.'"');
			return $id;
		}

		function edit($itemId, $field, $value) {
			global $database, $db;
			return ($db->execute('UPDATE '.$database['prefix'].'FeedItems SET '.$field.'="'.$db->escape($value).'" WHERE id='.$itemId))?true:false;
		}

		function editWithArray($itemId, $arg){
			if (!isset($itemId) || !is_array($arg)) {
				return false;
			}

			foreach ($arg as $key=>$value) {
				if (!Validator::enum($key, 'author,permalink,title,autoUpdate,allowRedistribute,tags,category,focus,visibility')) {
					return false;
				}
				if (!FeedItem::edit($itemId, $key, $value)) {
					return false;
				}
			}
			return true;
		}

		function delete($itemId) {
			global $database, $db;
			list($feedId, $permalink) = FeedItem::gets($itemId, 'feed,permalink');
			if (!$db->execute("INSERT INTO {$database['prefix']}DeleteHistory (feed, permalink) VALUES ('$feedId', '$permalink')"))
				return false;
	
			requireComponent('LZ.PHP.Media');
			Media::delete($itemId);
			
			$db->execute("DELETE FROM {$database['prefix']}TagRelations WHERE item = {$itemId}"); // clear TagRelations

			if ($db->execute('DELETE FROM '.$database['prefix'].'FeedItems WHERE id='.$itemId)) {
				if (Validator::getBool(Settings::get('useRssOut'))) {
					requireComponent('Bloglounge.Data.RSSOut');
					RSSOut::refresh();
				}
				return true;
			} else {
				return false;
			}
		}

		function deleteByFeedId($feedId) {
			global $database, $db;

			$itemIds = array();

			requireComponent('LZ.PHP.Media');

			$result = $db->queryAll("SELECT id FROM {$database['prefix']}FeedItems WHERE feed='$feedId'");
			if($result) {
				foreach($result as $item) {
					Media::delete($item['id']);
					array_push($itemIds, $item['id']);			
				}
			
				$itemStr = implode(',', $itemIds);
				$db->execute("DELETE FROM {$database['prefix']}TagRelations WHERE item IN ($itemStr)"); // clear TagRelations
				
				if ($db->execute('DELETE FROM '.$database['prefix'].'FeedItems WHERE feed='.$feedId)) {
					if (Validator::getBool(Settings::get('useRssOut'))) {
						requireComponent('Bloglounge.Data.RSSOut');
						RSSOut::refresh();
					}
					return true;
				} else {
					return false;
				}
			}

			return true;
		}

		function click($url) {
			global $database, $db;
			return $db->execute('UPDATE '.$database['prefix'].'FeedItems SET click=click+1 WHERE permalink="'.$db->escape($url).'"');
		}

		function doesHaveOwnership($itemId) {
			global $database, $db, $session;
			$feedId = FeedItem::get($itemId, 'feed');
			return ($db->count('SELECT owner FROM '.$database['prefix'].'Feeds WHERE owner="'.$session['id'].'" and id="'.$feedId.'"') != 0) ? true : false;
		}

		function setThumbnail($itemId, $thumbnailId) {
			global $database, $db;
			if(empty($itemId) || empty($thumbnailId)) {
				return false;
			}
			
			requireComponent('LZ.PHP.Media');
			
			if(Media::checkMedia($thumbnailId)) {
				$db->execute("UPDATE {$database['prefix']}FeedItems SET thumbnailId='$thumbnailId' WHERE id='$itemId'");
				return true;
			}
			return false;
		}
		
		function cacheThumbnail($itemId, $item) {
			global $database, $db;
			if (!isset($item) || !is_array($item) || !defined('ROOT') || !isset($itemId) || !Validator::getBool($itemId))
				return false;

			$cacheDir = ROOT. '/cache/thumbnail';
			if (!is_dir($cacheDir)) func::mkpath($cacheDir);
			if (!is_writeable($cacheDir)) return false;

			$division = ord(substr(str_replace("http://","",$item['permalink']), 0, 1));

			requireComponent('LZ.PHP.Media');
			$media = new Media;
			$media->set('outputPath', $cacheDir.'/'.$division);
			if (!$result = $media->get($item, Settings::get('thumbnailLimit')))
				return false;
			
			// 1.2 			
			foreach($result['images'] as $item) {
				$tFilename = $db->escape(str_replace($cacheDir, '', $item['filename']['fullpath']));
				$tSource = $db->escape($item['source']);

				if(!empty($tFilename) && $item['width'] > 100 && $item['height'] > 100) {
					$width = $item['width'];
					$height = $item['height'];
					$insertId = $media->add($itemId, $tFilename, $tSource, $width, $height, 'image');
				}
			}

			foreach($result['movies'] as $item) {
				$tFilename = $db->escape(str_replace($cacheDir, '', $item['filename']['fullpath']));
				$tSource = $db->escape($item['source']);

				if(!empty($tFilename)) {
					$width = $item['width'];
					$height = $item['height'];
					$via = $item['via'];

					$insertId = $media->add($itemId, $tFilename, $tSource, $width, $height, 'movie', $via);
				}
			}
	
			if(isset($insertId)) {
				$db->execute("UPDATE {$database['prefix']}FeedItems SET thumbnailId='$insertId' WHERE id='$itemId'");
			}
		}

		/** gets **/

		function getPredictionPage($id, $pageCount, $searchType='', $searchKeyword='',$searchExtraValue='', $viewDelete = false, $owner = 0) {
			global $db, $database;

			$page = 1;

			$sQuery = FeedItem::getFeedItemsQuery($searchType, $searchKeyword, $searchExtraValue, $viewDelete, $owner);
			$written = FeedItem::get($id,'written');

			if(!empty($written)) {
				$sQuery = str_replace('WHERE', 'WHERE (i.written > '.$written.')'.' AND ',$sQuery);
			}		
			
			$count = $db->queryCell('SELECT count(*) as count FROM '.$database['prefix'].'FeedItems i '.$sQuery.' ORDER BY i.written DESC');

			if($count > 0) {
				$page = ceil(($count + 1) / $pageCount);
			}

			return $page;
		}

		function getFeedItemCount($filter='') {
			global $db, $database;		
			if (!list($totalFeedItems) = $db->pick('SELECT count(i.id) FROM '.$database['prefix'].'FeedItems i '.$filter))
					$totalFeedItems = 0;
			return $totalFeedItems;
		}
		
		function getFeedItemsByOwner($owner, $searchType, $searchKeyword, $searchExtraValue, $page, $pageCount, $viewDelete = false) {
			return FeedItem::getFeedItems($searchType, $searchKeyword, $searchExtraValue, $page, $pageCount, $viewDelete, $owner);
		}

		function getFeedItems($searchType, $searchKeyword, $searchExtraValue, $page, $pageCount, $viewDelete = false, $owner = 0) {
			global $db, $database, $config;
			
			$sQuery = FeedItem::getFeedItemsQuery($searchType, $searchKeyword, $searchExtraValue,$viewDelete,$owner);
			
			$pageStart = ($page-1) * $pageCount; // ó�������� ��ȣ
			$feedList = $db->queryAll('SELECT i.id, i.feed, i.author, i.permalink, i.title, i.description, i.tags, i.written, i.click, i.thumbnailId, i.visibility, i.boomUp, i.boomDown, i.category, i.focus FROM '.$database['prefix'].'FeedItems i '.$sQuery.' ORDER BY i.written DESC LIMIT '.$pageStart.','.$pageCount);

			$feedItemCount = FeedItem::getFeedItemCount($sQuery);
			return array($feedList, $feedItemCount);
		}

		function getFeedItemsQuery($searchType, $searchKeyword, $searchExtraValue,$viewDelete = false,$owner = 0) {	
			global $db, $database, $config;

			$sQuery = '';
			if ($searchType=='tag' && !Validator::is_empty($searchKeyword)) {		
				if (!list($tagId) = $db->pick('SELECT id FROM '.$database['prefix'].'Tags WHERE name="'.$db->escape($searchKeyword).'"')) {
					return array(null,0);
				} else {
					$sQuery = ' LEFT JOIN '.$database['prefix'].'TagRelations r ON r.item = i.id WHERE r.tag="'.$tagId.'"';
				}

			} else if ($searchType=='blogURL' && !Validator::is_empty($searchKeyword)){		
				$searchKeyword = UTF8::bring($searchKeyword);
				$searchFeedId = $searchExtraValue;
				if(empty($searchFeedId)) {
					$searchFeedId = Feed::blogURL2Id('http://'.str_replace('http://', '', $searchKeyword));
				} 

				$sQuery = ' WHERE i.feed = '.$searchFeedId;
				
			} else if ($searchType=='title+description' && !Validator::is_empty($searchKeyword)){		
					$searchKeyword = UTF8::bring($searchKeyword);
					$keyword = $db->escape($searchKeyword);

					$sQuery =  ' WHERE i.description LIKE "%'.$keyword.'%"';				
			}  else if ($searchType=='title' && !Validator::is_empty($searchKeyword)){		
					$searchKeyword = UTF8::bring($searchKeyword);
					$keyword = $db->escape($searchKeyword);

					$sQuery =  ' WHERE i.title LIKE "%'.$keyword.'%"';				
			} else if ($searchType=='description' && !Validator::is_empty($searchKeyword)){		
					$searchKeyword = UTF8::bring($searchKeyword);
					$keyword = $db->escape($searchKeyword);

					$sQuery =  ' WHERE i.description LIKE "%'.$keyword.'%"';				
			} else if ($searchType=='focus'){		
					$sQuery =  ' WHERE i.focus = "'.$searchKeyword.'"';				
			} else if ($searchType=='category') {
				$category = Category::getByName($searchKeyword);
				if($category) {
					$sQuery = ' WHERE i.category = ' . $category['id'];
				}
			} else {
				if (!Validator::is_empty($searchKeyword)) {
					$searchKeyword = UTF8::bring($searchKeyword);
					$keyword = $db->escape($searchKeyword);
					
					if(empty($searchExtraValue)) { // all : title, description, tags, permlink						
						$sQuery =  ' WHERE i.author LIKE "%'.$keyword.'%" OR i.title LIKE "%'.$keyword.'%" OR i.description LIKE "%'.$keyword.'%" OR i.tags LIKE "%'.$keyword.'%" OR i.permalink LIKE "%'.$keyword.'%"';					
					} else { // custom
						$sQuery = ' WHERE ' . $searchExtraValue;
					}
				}
			}

			// boomDownReactor, boomDownReactorLimit : �����Ͱ� ������϶� �������� ���� ��Ʈ �߰� ( Ư������ŭ �մٿ�(����õ)�ѱ��� �����ϰų� Ư�����..
			if (($config->boomDownReactor == 'hide') && ($config->boomDownReactLimit > 0)) {
				$bQuery = ' WHERE (boomDown <= '.$config->boomDownReactLimit.') ';
				if (strpos($sQuery, 'WHERE') !== false) {
					$sQuery = str_replace('WHERE ', $bQuery.' AND (', $sQuery);
					$sQuery .= ')';
				} else {
					$sQuery .= $bQuery;
				}
			}


			// ��¥ ����
			if (!empty($searchKeyword) && ($searchType == 'archive')) {
				$tStart = $searchExtraValue;
				$tEnd = $tStart + 86400;

				$tQuery = ' WHERE i.written > '.$tStart.' AND i.written < '.$tEnd.' ';
				if (strpos($sQuery, 'WHERE') !== false) {
					$sQuery = str_replace('WHERE ', $tQuery.' AND (', $sQuery);
					$sQuery .= ')';
				} else {
					$sQuery .= $tQuery;
				}
			}

			if(empty($owner)) {
				if($viewDelete) {
					// ������ ��α׸� �̱�		
					if(!isAdmin()) {
						$bQuery = ' WHERE  (i.visibility = "d") AND (f.visibility = "y") ';
					} else {
						$bQuery = ' WHERE  (i.visibility = "d") ';
					}
				} else {
					// ������ ��α׸� �̱�		
					if(!isAdmin()) {
						$bQuery = ' WHERE  (i.visibility = "y") AND (f.visibility = "y") ';
					} else {
						$bQuery = ' WHERE  (i.visibility != "d") ';
					}
				}
			} else {		
				if($viewDelete) {
					// ������ ��α׸� �̱�		
					if(!isAdmin()) {
						$bQuery = ' WHERE  (i.visibility = "d") AND (f.visibility = "y") AND (f.owner = ' . $owner . ')';
					} else {
						$bQuery = ' WHERE  (i.visibility = "d") AND (f.owner = ' . $owner . ')';
					}
				} else {
					// ������ ��α׸� �̱�		
					if(!isAdmin()) {
						$bQuery = ' WHERE  (i.visibility = "y") AND (f.visibility = "y") AND (f.owner = ' . $owner . ')';
					} else {
						$bQuery = ' WHERE  (i.visibility != "d") AND (f.owner = ' . $owner . ')';
					}
				}
			}

			if(strpos($sQuery, 'Feeds f') === false ) {
				$bQuery = ' LEFT JOIN '.$database['prefix'].'Feeds f ON f.id = i.feed ' . $bQuery;
			}
			if (strpos($sQuery, 'WHERE') !== false) {
				$sQuery = str_replace('WHERE ', $bQuery.' AND (', $sQuery);
				$sQuery .= ')';
			} else {
				$sQuery .= $bQuery;
			}

			return $sQuery;
		}

		function getFeedItem($id) {		
			global $db, $database;
			return $db->queryRow('SELECT * FROM '.$database['prefix'].'FeedItems WHERE id='. $id);
		}

		function getFeedItemsByFeedId($feedId, $count) {		
			global $db, $database;
			return $db->queryAll('SELECT * FROM '.$database['prefix'].'FeedItems WHERE visibility = "y" AND feed = '. $feedId .' ORDER BY written DESC LIMIT '. $count);
		}

		function getRecentFeedItems($count) {		
			global $db, $database;
			return $db->queryAll('SELECT * FROM '.$database['prefix'].'FeedItems WHERE visibility = "y" ORDER BY written DESC LIMIT '. $count);
		}

		function getRecentFeedItemsByFeed($feeds, $count) {		
			global $db, $database;
			if(is_array($feeds)) {
				return $db->queryAll('SELECT * FROM '.$database['prefix'].'FeedItems WHERE feed IN ('. implode(',',$feeds) .') AND visibility = "y" ORDER BY written DESC LIMIT '. $count);
			} else {
				return $db->queryAll('SELECT * FROM '.$database['prefix'].'FeedItems WHERE feed = ' . $feeds . ' AND visibility = "y" ORDER BY written DESC LIMIT '. $count);
			}
		}	
		
		function getRecentFeedItemsByCategory($categories, $count) {		
			global $db, $database;
			if(is_array($categories)) {
				return $db->queryAll('SELECT * FROM '.$database['prefix'].'FeedItems WHERE category IN ('. implode(',',$categories) .') AND visibility = "y" ORDER BY written DESC LIMIT '. $count);
			} else {
				return $db->queryAll('SELECT * FROM '.$database['prefix'].'FeedItems WHERE category = ' . $categories . ' AND visibility = "y" ORDER BY written DESC LIMIT '. $count);
			}
		}

		function getRecentFocusFeedItems($count) {
			global $db, $database;
			return $db->queryAll('SELECT id,permalink,title,description,author,thumbnailId FROM '.$database['prefix'].'FeedItems WHERE focus = "y" AND visibility = "y" ORDER BY written DESC LIMIT '. $count);
		}

		function getTopFeedItems($count, $rankBy = 'boom') {		
			global $db, $database, $config;	
			
			switch ($rankBy) {
				case 'click':
					$rankBy = 'i.click';
				break;
				default:
				case 'boom':
					$rankBy = 'i.boomUp-i.boomDown';
				break;
			}

			$qBoom = '';
			return $db->queryAll('SELECT i.permalink, i.title, i.description FROM '.$database['prefix'].'FeedItems i '.$qBoom.' ORDER BY ('.$rankBy.') DESC LIMIT 0,'.$count);
		}	
		
		// -- �Ʒ����·� .. ���� (��õ��) - ((���� - ���� ���� ��)���� * 100000) // ���ú��� ����.. ����.. �ײ����� ������ ���� ���� �༭.. ������ �ű��. 
		// ���� �ֱ��� ���� �켱������ ���� �ű� ( ���� ������Ʈ�� ������� ������� �ֱٱ��� �׻� �α���� ��.. )
		// ��õ Ȥ�� ����õ�� ��¥�� �ƴ� �۹���� ���� ���õ�..

		function getTopFeedItemsByLastest($count, $rankBy = 'boom') {		
			global $db, $database, $config;	

			$written = $db->queryCell('SELECT written FROM '.$database['prefix'].'FeedItems ORDER BY written ASC');
			if(!$written) $written = 0;
			$written = date('Ymd', $written);

			switch ($rankBy) {
				case 'click':
				//	$rankBy = 'i.click-ROUND(('.gmmktime().'-i.written)/(24*60*60))*10000';
					$rankBy = 'i.click+((FROM_UNIXTIME(i.written,"%Y%m%d")-'.$written.')*10000)';
				break;
				default:
				case 'boom':
				//	$rankBy = 'i.boomUp-i.boomDown-ROUND(('.gmmktime().'-i.written)/(24*60*60))*10000';
					$rankBy = 'i.boomUp-i.boomDown+((FROM_UNIXTIME(i.written,"%Y%m%d")-'.$written.')*10000)';
				break;
			}
			$qBoom = '';
			return $db->queryAll('SELECT i.id, i.permalink, i.title, i.description FROM '.$database['prefix'].'FeedItems i '.$qBoom.' ORDER BY ('.$rankBy.') DESC LIMIT 0,'.$count);
		}
	}
?>