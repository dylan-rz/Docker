<?php
/* Developed by Kernel Team.
   http://kernel-team.com
*/

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);
$api_version = '5.3.0';

// --- CORS support: allow browser-based clients (preflight + actual requests)
// Builds a permissive set of CORS headers similar to the nginx locations.
// Adjust origins/headers/methods as needed for tighter security.
function rc_send_cors_headers()
{
	// Mirror Origin if present, otherwise wildcard
	$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : "*";
	// You can tighten this by checking $origin against a whitelist.
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Range,Content-Type,Authorization,Origin,Accept');
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Expose-Headers: Content-Length,Accept-Ranges,Content-Range,X-Cache,X-Accel-Redirect,KVS-Errno,KVS-IP');
	header('Vary: Origin');
}

// Respond to OPTIONS preflight and exit early with no body (204 No Content)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	rc_send_cors_headers();
	http_response_code(204);
	// Some user agents expect a Content-Length: 0 for empty 204 responses
	header('Content-Length: 0');
	exit;
}

// Ensure all non-OPTIONS responses include CORS headers as well
rc_send_cors_headers();

// comma separated list of whitelisted IPs
$whitelist_ips = "*";

// comma separated list of whitelisted referers
$whitelist_referers = "*";

// the number of seconds temp links are valid
$ttl = 3600;

######################################################################################

$config['cv']="007353236991cc7e38d50b66b4d40270";

if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
{
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
	if (strpos($_SERVER['REMOTE_ADDR'], ',') !== false)
	{
		$_SERVER['REMOTE_ADDR'] = trim(substr($_SERVER['REMOTE_ADDR'], 0, strpos($_SERVER['REMOTE_ADDR'], ',')));
	}
} elseif (isset($_SERVER['HTTP_X_REAL_IP']))
{
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_REAL_IP'];
}

if ($_REQUEST['action'] == '' && $_REQUEST['file'] == '')
{
	echo "connected.";
	die;
} elseif ($_REQUEST['action'] == 'version')
{
	echo $api_version;
	die;
} elseif ($_REQUEST['action'] == 'ip')
{
	echo $_SERVER['REMOTE_ADDR'];
	die;
} elseif ($_REQUEST['action'] == 'path')
{
	if ($_REQUEST['cv'] != $config['cv'])
	{
		sleep(1);
		http_response_code(403);
		header("KVS-Errno: 2");
		echo "Access denied (errno 2)";
		die;
	}
	echo dirname($_SERVER['SCRIPT_FILENAME']);
} elseif ($_REQUEST['action'] == 'status')
{
	if (function_exists('sys_getloadavg'))
	{
		$load = sys_getloadavg();
	} else
	{
		$load = [0];
	}
	$load = floatval($load[0]);
	if ($_REQUEST['content_path'] != '' && (is_dir(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]") || is_link(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]")))
	{
		$total_space = @disk_total_space(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]");
		$free_space = @disk_free_space(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]");
	} else
	{
		$total_space = @disk_total_space(dirname($_SERVER['SCRIPT_FILENAME']));
		$free_space = @disk_free_space(dirname($_SERVER['SCRIPT_FILENAME']));
	}
	echo "$load|$total_space|$free_space";
	die;
} elseif ($_REQUEST['action'] == 'time')
{
	echo time();
	die;
} elseif ($_REQUEST['action'] == 'check')
{
	$content_path = $_REQUEST['content_path'];
	$paths = explode('||', $_REQUEST['files']);
	foreach ($paths as $path)
	{
		if ($path)
		{
			$path_rec = explode('|', $path);
			if ($content_path && $path_rec[0])
			{
				$path_rec[0] = "$content_path/$path_rec[0]";
			}
			if ($path_rec[1] > 0)
			{
				if (sprintf("%.0f", @filesize($path_rec[0])) != $path_rec[1])
				{
					echo "$path_rec[0] (expected size $path_rec[1])";
					die;
				}
			} else
			{
				if (sprintf("%.0f", @filesize($path_rec[0])) < 1)
				{
					echo $path_rec[0];
					die;
				}
			}
		}
	}
	echo '1';
	die;
} elseif ($_REQUEST['file'] <> '')
{
	$time = intval($_REQUEST['time']);
	$limit = intval($_REQUEST['lr']);
	$cv = trim($_REQUEST['cv2']);
	$target_file = rawurldecode($_REQUEST['file']);
	// Preserve the originally requested path so we can serve the raw MP4 for downloads
	$original_target_file = $target_file;
	$is_download = trim($_GET['download']);

	if (strpos($target_file, 'B64') === 0)
	{
		$target_file_info = @unserialize(base64_decode(substr($target_file, 3)));

		if (!isset($target_file_info['time'], $target_file_info['cv'], $target_file_info['file']))
		{
			http_response_code(403);
			header("KVS-Errno: 2");
			echo "Access denied (errno 2)";
			die;
		}

		if ($target_file_info['time'] < time() - $ttl || $target_file_info['time'] > time() + $ttl)
		{
			http_response_code(403);
			header("KVS-Errno: 3");
			echo "Access denied (errno 3)";
			die;
		}

		$allowed_ips = explode(',', trim($_COOKIE["kt_remote_ips"]));
		if (md5($target_file_info['time'] . $target_file_info['limit'] . $target_file_info['file'] . $_SERVER['REMOTE_ADDR'] . $config['cv']) !== $target_file_info['cv'])
		{
			$ip_valid = false;
			foreach ($allowed_ips as $allowed_ip)
			{
				$allowed_ip = explode("||", $allowed_ip);
				if ($allowed_ip[1] === md5($allowed_ip[0] . $config['cv']))
				{
					if (md5($target_file_info['time'] . $target_file_info['limit'] . $target_file_info['file'] . $allowed_ip[0] . $config['cv']) === $target_file_info['cv'])
					{
						$ip_valid = true;
						break;
					}
				}
			}
			if (!$ip_valid && $whitelist_ips)
			{
				$whitelist_ips = array_map('trim', explode(',', trim($whitelist_ips)));
				// Treat a single '*' entry as wildcard allowing any IP
				if (in_array('*', $whitelist_ips, true)) {
					$ip_valid = true;
				} else {
					foreach ($whitelist_ips as $whitelist_ip)
					{
						if ($whitelist_ip == $_SERVER['REMOTE_ADDR'] || md5($target_file_info['time'] . $target_file_info['limit'] . $target_file_info['file'] . $whitelist_ip . $config['cv']) === $target_file_info['cv'])
						{
							$ip_valid = true;
							break;
						}
					}
				}
			}
			if (!$ip_valid)
			{
				http_response_code(403);
				header("KVS-Errno: 4");
				header("KVS-IP: $_SERVER[REMOTE_ADDR]");
				echo "Access denied (errno 4)";
				die;
			}
		} else
		{
			$has_ip_cookie = false;
			foreach ($allowed_ips as $allowed_ip)
			{
				$allowed_ip = explode("||", $allowed_ip);
				if ($allowed_ip[0] == $_SERVER['REMOTE_ADDR'])
				{
					$has_ip_cookie = true;
				}
			}
			if (!$has_ip_cookie)
			{
				$allowed_ips[] = $_SERVER['REMOTE_ADDR'] . '||' . md5($_SERVER['REMOTE_ADDR'] . $config['cv']);
				if (version_compare(PHP_VERSION, '7.3.0') >= 0)
				{
					setcookie("kt_remote_ips", implode(',', $allowed_ips), ['expires' => time() + $ttl, 'path' => '/', 'samesite' => 'Lax']);
				} else
				{
					setcookie("kt_remote_ips", implode(',', $allowed_ips), time() + $ttl, "/");
				}
			}
		}

		$target_file = $target_file_info['file'];
		$limit = $target_file_info['limit'];
	} else
	{
		if ($time < time() - $ttl || $time > time() + $ttl)
		{
			http_response_code(403);
			header("KVS-Errno: 3");
			echo "Access denied (errno 3)";
			die;
		}

		if (md5($time . $limit . $config['cv']) !== $cv)
		{
			http_response_code(403);
			header("KVS-Errno: 4");
			echo "Access denied (errno 4)";
			die;
		}

		if ($_SERVER['HTTP_REFERER'] != '' && $_REQUEST['cv3'] != '')
		{
			$ref_host = parse_url(str_replace('www.', '', $_SERVER['HTTP_REFERER']), PHP_URL_HOST);
			if ($ref_host != '' && $ref_host != $_SERVER['SERVER_NAME'] && md5($ref_host . $config['cv']) !== trim($_REQUEST['cv3']))
			{
				$referer_valid = false;
				$whitelist_referers = array_map('trim', explode(',', trim($whitelist_referers)));
				// Treat '*' as allow-all for referers
				if (in_array('*', $whitelist_referers, true)) {
					$referer_valid = true;
				} else {
					foreach ($whitelist_referers as $whitelist_referer)
					{
						if ($whitelist_referer == $ref_host)
						{
							$referer_valid = true;
							break;
						}
					}
				}

				if (!$referer_valid)
				{
					http_response_code(403);
					header("KVS-Errno: 5");
					echo "Access denied (errno 5)";
					die;
				}
			}
		}

		if (md5($target_file . $config['cv']) !== trim($_REQUEST['cv4']))
		{
			http_response_code(403);
			header("KVS-Errno: 6");
			echo "Access denied (errno 6)";
			die;
		}
	}

	// If the target is an MP4 file, rewrite it to a directory-style HLS manifest
	// Convert "/hls/.../video.mp4" -> "/hls/.../video/index.m3u8" so the
	// nginx-vod-module or origin can serve a standard index.m3u8 manifest.
	if (preg_match('#/([^/]+)\.mp4$#i', $target_file, $m))
	{
		$dir = substr($target_file, 0, -strlen($m[0]));
		// Ensure directory ends with a slash before concatenation
		$dir = rtrim($dir, '/') . '/';
		// Use the filename without extension as the basename
		$basename = $m[1];
		// Make the parent directory include the .mp4 suffix so the vod module
		// will request parent.mp4 from the upstream (avoids missing .mp4).
		$target_file = $dir . $basename . '.mp4/index.m3u8';
	}
	// If target already points to a directory-style index.m3u8, normalize the
	// path so the parent directory contains the expected .mp4 suffix. If the
	// parent already includes .mp4, keep it; otherwise append .mp4 so upstream
	// requests include the extension.
	elseif (preg_match('#/(.+)/([^/]+)/index\.m3u8$#i', $target_file, $m2))
	{
		// $m2[1] = path before the parent dir, $m2[2] = parent dir name
		$parent = $m2[2];
		if (preg_match('#\.mp4$#i', $parent)) {
			$parent_dir = $parent;
		} else {
			$parent_dir = $parent . '.mp4';
		}
		$target_file = '/' . trim($m2[1], '/') . '/' . $parent_dir . '/index.m3u8';
	}

	if (floatval($_REQUEST['start']) > 0)
	{
		$start_str = "?start=" . floatval($_REQUEST['start']);
	}

	if (strpos($target_file, ".flv") !== false)
	{
		header("Content-Type: video/x-flv");
	} elseif (
		// match .mp4 only when the path actually ends with .mp4 (no trailing slash)
		(substr($target_file, -4) === '.mp4') ||
		// or when .mp4 appears and is not followed by a slash (avoid matching '/video.mp4/index.m3u8')
		(preg_match('#\.mp4($|[^/])#i', $target_file) === 1)
	)
	{
		header("Content-Type: video/mp4");
	} elseif (preg_match('#\.m3u8$#i', $target_file))
	{
		header('Content-Type: application/vnd.apple.mpegurl');
	} elseif (strpos($target_file, ".webm") !== false)
	{
		header("Content-Type: video/webm");
	} elseif (strpos($target_file, ".jpg") !== false)
	{
		header("Content-Type: image/jpeg");
	} elseif (strpos($target_file, ".gif") !== false)
	{
		header("Content-Type: image/gif");
	} elseif (strpos($target_file, ".zip") !== false)
	{
		header("Content-Type: application/zip");
	} else
	{
		header("Content-Type: application/octet-stream");
	}

	if (intval($limit) > 0)
	{
		header("X-Accel-Limit-Rate: $limit");
	}
	$short_file_name = basename($target_file);
	if ($_REQUEST['download_filename'] <> '')
	{
		$short_file_name = $_REQUEST['download_filename'];
	}
	if ($is_download == 'true')
	{
		header("Content-Disposition: attachment; filename=\"$short_file_name\"");
	} else
	{
		header("Content-Disposition: inline; filename=\"$short_file_name\"");
	}

	// If this request is already the validator round-trip, perform an internal
	// X-Accel-Redirect so the edge nginx serves the manifest/file without
	// issuing another client redirect. This prevents redirect loops.
	// DEBUG: log request keys and validator flag for debugging
	error_log("[remote_control] _REQUEST keys: " . implode(',', array_keys($_REQUEST)));
	error_log("[remote_control] _validator present: " . (isset($_REQUEST['_validator']) ? $_REQUEST['_validator'] : 'none'));
	if (isset($_REQUEST['_validator']) && $_REQUEST['_validator'] == '1')
	{
		// DEBUG: log the resolved target file and what we will X-Accel-Redirect to
		error_log("[remote_control] validator: target_file = " . $target_file);

		// If this was requested as a download, we want to bypass the vod
		// repackaging (which produces an m3u8) and instead fetch the original
		// MP4 from the origin via the /upstream/ proxy. Build an internal
		// X-Accel-Redirect to /upstream/<original-path> so nginx proxies the
		// raw MP4 from the origin.
		if ($is_download === 'true') {
			$orig = $original_target_file;
			// Normalize leading slash
			if (strpos($orig, '/') !== 0) {
				$orig = '/' . $orig;
			}

			// If the requested resource is an index.m3u8, map it back to the
			// parent MP4 so the origin returns the raw MP4 for download.
			$mp4_path = $orig;
			if (preg_match('#/index\.m3u8$#i', $mp4_path)) {
				// remove trailing /index.m3u8
				$mp4_path = preg_replace('#/index\.m3u8$#i', '', $mp4_path);
				// if the parent does not end with .mp4, append .mp4
				$base = basename($mp4_path);
				if (!preg_match('#\.mp4$#i', $base)) {
					$mp4_path = rtrim($mp4_path, '/') . '.mp4';
				}
			}

			// Preserve original query string but remove any internal _validator flag
			$qs = $_SERVER['QUERY_STRING'] ?? '';
			// remove _validator=1 when present
			$qs = preg_replace('/(?:^|&)_validator=1(?:&|$)/', '&', $qs);
			$qs = trim($qs, '&');

			$internal_mp4 = '/upstream' . $mp4_path . ($qs ? ('?' . $qs) : '');
			error_log("[remote_control] download: internal X-Accel-Redirect to mp4: " . $internal_mp4);
			header('X-Accel-Redirect: ' . $internal_mp4);
			exit;
		}

		// Ensure the target points to a manifest (index.m3u8). If it's an mp4
		// path, convert it to the directory-style manifest path so nginx's
		// vod module can repackage MP4 -> HLS when the internal redirect hits
		// the /hls/ location.
		$manifest = $target_file;
		if (!preg_match('#\.m3u8$#i', $manifest)) {
			if (preg_match('#/([^/]+)\.mp4$#i', $manifest, $m)) {
				$dir = substr($manifest, 0, -strlen($m[0]));
				$manifest = $dir . $m[0] . '/index.m3u8';
			} elseif (preg_match('#/(.+)/([^/]+)/index\.m3u8$#i', $manifest, $m2)) {
				// If the parent directory already ends with .mp4, don't append another .mp4
				$parent = $m2[2];
				if (preg_match('#\.mp4$#i', $parent)) {
					$manifest = '/' . trim($m2[1], '/') . '/' . $parent . '/index.m3u8';
				} else {
					$manifest = '/' . trim($m2[1], '/') . '/' . $parent . '.mp4/index.m3u8';
				}
			} else {
				$manifest = rtrim($manifest, '/') . '/index.m3u8';
			}
		}

		// Ensure leading slash
		if (strpos($manifest, '/') !== 0) {
			$manifest = '/' . ltrim($manifest, '/');
		}

		// If the manifest corresponds to a directory-style manifest for a base
		// MP4 (e.g. /path/video.mp4/index.m3u8) then build a master.m3u8
		// dynamically listing available resolution variants. We probe the
		// edge's /upstream proxy for each variant MP4; if the origin has the
		// file the proxy will generally return 200 and we include that variant.
		// This runs only for validated requests (this code is inside the
		// _validator==1 branch) so it's safe to return the master directly.

		$variant_suffixes = [
			'_2160p' => ['res' => '3840x2160', 'bw' => 15000000],
			'_1080p' => ['res' => '1920x1080', 'bw' => 5000000],
			'_720p'  => ['res' => '1280x720',  'bw' => 3000000],
			'_480p'  => ['res' => '854x480',   'bw' => 1000000],
			'_360p'  => ['res' => '640x360',   'bw' => 700000],
			// Some sources use '_360' without the trailing 'p'
			'_360'   => ['res' => '640x360',   'bw' => 700000],
			'_preview' => ['res' => '320x180', 'bw' => 200000],
		];

		// --- Helper: small cache layer using APCu when available, otherwise file-based
		function rc_cache_get($key) {
			if (function_exists('apcu_fetch')) {
				$ok = false; $v = apcu_fetch($key, $ok); return $ok ? $v : false;
			}
			$f = sys_get_temp_dir() . '/rc_' . md5($key);
			if (!is_file($f)) return false;
			$data = @file_get_contents($f);
			if ($data === false) return false;
			$obj = @unserialize($data);
			if (!$obj || !isset($obj['exp']) || $obj['exp'] < time()) return false;
			return $obj['v'];
		}
		function rc_cache_set($key, $val, $ttl=60) {
			if (function_exists('apcu_store')) return apcu_store($key, $val, $ttl);
			$f = sys_get_temp_dir() . '/rc_' . md5($key);
			$obj = ['v'=>$val,'exp'=>time()+$ttl];
			@file_put_contents($f, serialize($obj), LOCK_EX);
			return true;
		}

		// --- Helper: simple lock (APCu add() if available, otherwise file lock)
		function rc_acquire_lock($key, $ttl = 5) {
			if (function_exists('apcu_add')) {
				// apcu_add returns false if the key exists
				return apcu_add($key, 1, $ttl);
			}
			// Fallback: file-based lock using sys temp dir
			$f = sys_get_temp_dir() . '/rc_lock_' . md5($key);
			$fp = @fopen($f, 'w');
			if (!$fp) return false;
			$got = flock($fp, LOCK_EX | LOCK_NB);
			if ($got) {
				ftruncate($fp, 0);
				fwrite($fp, (string)(time()+$ttl));
				fflush($fp);
				// keep handle in global map so it is not closed (and lock released)
				$GLOBALS['__rc_locks__'][$f] = $fp;
				return true;
			}
			fclose($fp);
			return false;
		}
		function rc_release_lock($key) {
			if (function_exists('apcu_delete')) {
				return apcu_delete($key);
			}
			$f = sys_get_temp_dir() . '/rc_lock_' . md5($key);
			if (isset($GLOBALS['__rc_locks__'][$f])) {
				$fp = $GLOBALS['__rc_locks__'][$f];
				flock($fp, LOCK_UN);
				fclose($fp);
				unset($GLOBALS['__rc_locks__'][$f]);
				@unlink($f);
				return true;
			}
			// Nothing to release
			return false;
		}

		// --- Helper: parallel HEAD requests using curl_multi
		function rc_curl_multi_head(array $urls, $timeout = 2) {
			$mh = curl_multi_init();
			$handles = [];
			foreach ($urls as $id => $url) {
				$ch = curl_init();
				curl_setopt_array($ch, [
					CURLOPT_URL => $url,
					CURLOPT_NOBODY => true,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => $timeout,
					CURLOPT_CONNECTTIMEOUT => 1,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FORBID_REUSE => false,
					CURLOPT_HTTPHEADER => ['Connection: keep-alive'],
				]);
				curl_multi_add_handle($mh, $ch);
				$handles[$id] = $ch;
			}
			// execute
			$running = null;
			do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.01); } while ($running > 0);
			$results = [];
			foreach ($handles as $id => $ch) {
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$results[$id] = intval($code);
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);
			return $results;
		}

		// --- Helper: parallel GET requests using curl_multi (returns body map)
		function rc_curl_multi_get(array $urls, $timeout = 3) {
			$mh = curl_multi_init();
			$handles = [];
			foreach ($urls as $id => $url) {
				$ch = curl_init();
				curl_setopt_array($ch, [
					CURLOPT_URL => $url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => $timeout,
					CURLOPT_CONNECTTIMEOUT => 1,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FORBID_REUSE => false,
					CURLOPT_HTTPHEADER => ['Connection: keep-alive'],
				]);
				curl_multi_add_handle($mh, $ch);
				$handles[$id] = $ch;
			}
			$running = null;
			do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.01); } while ($running > 0);
			$results = [];
			foreach ($handles as $id => $ch) {
				$body = curl_multi_getcontent($ch);
				$results[$id] = $body !== false ? $body : false;
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);
			return $results;
		}

		// Only consider manifests that are of form .../<parent>.mp4/index.m3u8
		if (preg_match('#/(.*/)?([^/]+)\.mp4/index\.m3u8$#i', $manifest, $mm)) {
			$dir_prefix = isset($mm[1]) ? $mm[1] : '';
			$parent_name = $mm[2];
			// Default base name to the parent; we'll strip suffixes below when needed.
			$base_name = $parent_name;

			// If the request is explicitly for the preview variant, do not
			// construct a master playlist here — allow the normal internal
			// redirect to produce the preview manifest as a standalone playlist.
			if (preg_match('#_preview$#i', $parent_name)) {
				// skip master generation for preview
			} else {
				// If the parent directory already includes a variant suffix
				// (e.g. 4012085_360 or 4012085_360p), strip it to get the
				// canonical base name so we can probe for other variants.
				$variant_keys = array_keys($variant_suffixes);
				$base_name = $parent_name;
				foreach ($variant_keys as $vsuf) {
					if (substr($parent_name, -strlen($vsuf)) === $vsuf) {
						$base_name = substr($parent_name, 0, -strlen($vsuf));
						$base_name = rtrim($base_name, '_');
						break;
					}
				}
			}

			// Build probe base path (without leading slash)
			$dir_trim = rtrim($dir_prefix, '/');
			$base_path = ($dir_trim === '' ? '' : '/' . $dir_trim) . '/' . $base_name;

			$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
			$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

			// If this is the preview variant, try to fetch the generated preview
			// manifest from the upstream (which triggers vod-module packaging) and
			// return it directly from PHP with a Content-Length so downstream
			// proxies/clients see it. If we fail, fall through to the normal
			// X-Accel-Redirect path.
			if (preg_match('#_preview$#i', $parent_name)) {
				$dir_trim = rtrim($dir_prefix, '/');
				$manifest_path = ($dir_trim === '' ? '' : '/' . $dir_trim) . '/' . $parent_name . '.mp4/index.m3u8';
				$probe_url = $scheme . '://' . $host . '/upstream' . $manifest_path;
				$ch = curl_init();
				curl_setopt_array($ch, [
					CURLOPT_URL => $probe_url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => 3,
					CURLOPT_CONNECTTIMEOUT => 1,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_ENCODING => '',
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FORBID_REUSE => false,
				]);
				$body = curl_exec($ch);
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				if (($code === 200 || $code === 206) && $body !== false && strlen($body) > 0) {
					header('Content-Type: application/vnd.apple.mpegurl');
					// Provide Content-Length so downstream proxies/clients can see size
					header('Content-Length: ' . strlen($body));
					echo $body;
					exit;
				}
				// If upstream fetch failed, try fetching from the local edge manifest
				$local_url = $scheme . '://' . $host . $manifest . ($qs ? ('?' . $qs) : '');
				$ch2 = curl_init();
				curl_setopt_array($ch2, [
					CURLOPT_URL => $local_url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => 3,
					CURLOPT_CONNECTTIMEOUT => 1,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_ENCODING => '',
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FORBID_REUSE => false,
				]);
				$body2 = curl_exec($ch2);
				$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
				curl_close($ch2);
				if (($code2 === 200 || $code2 === 206) && $body2 !== false && strlen($body2) > 0) {
					header('Content-Type: application/vnd.apple.mpegurl');
					// Provide Content-Length so downstream proxies/clients can see size
					header('Content-Length: ' . strlen($body2));
					echo $body2;
					exit;
				}
				// If both fail, fall back to internal redirect (though Content-Length won't be set)
				$qs = $_SERVER['QUERY_STRING'] ?? '';
				if (strpos($qs, '_validator=1') === false) {
					$qs = ($qs === '' ? '' : ($qs . '&')) . '_validator=1';
				}
				$internal_preview = $manifest . ($qs ? ('?' . $qs) : '');
				error_log("[remote_control] preview: falling back to internal X-Accel-Redirect: $internal_preview");
				header('X-Accel-Redirect: ' . $internal_preview);
				exit;
			}

			$available = [];
			// Build probe list and keys
			$probe_map = [];
			foreach ($variant_suffixes as $suf => $meta) {
				if ($suf === '_preview') continue;
				$variant_mp4 = $base_path . $suf . '.mp4';
				$probe_map[$suf] = $scheme . '://' . $host . '/upstream' . $variant_mp4;
			}
			// First, try to fetch HTTP codes in parallel, using cache where possible
			$to_probe = [];
			foreach ($probe_map as $suf => $url) {
				$key = 'probe:' . md5($url);
				$cached = rc_cache_get($key);
				if ($cached !== false) {
					if ($cached === true) {
						$variant_manifest = $base_path . $suf . '.mp4' . '/index.m3u8';
						$available[$suf] = ['manifest' => $variant_manifest, 'res' => $variant_suffixes[$suf]['res'], 'bw' => $variant_suffixes[$suf]['bw']];
					}
				} else {
					$to_probe[$suf] = $url;
				}
			}
			if (count($to_probe) > 0) {
				$codes = rc_curl_multi_head($to_probe, 2);
				foreach ($codes as $suf => $code) {
					$key = 'probe:' . md5($to_probe[$suf]);
					$ok = ($code === 200 || $code === 206);
					rc_cache_set($key, $ok, 60);
					if ($ok) {
						$variant_manifest = $base_path . $suf . '.mp4' . '/index.m3u8';
						$available[$suf] = ['manifest' => $variant_manifest, 'res' => $variant_suffixes[$suf]['res'], 'bw' => $variant_suffixes[$suf]['bw']];
					}
				}
			}

			if (count($available) > 0) {
				// Try to pre-warm each variant manifest so the vod module has a
				// chance to compute segment durations / EXT-X-TARGETDURATION.
				// We'll fetch the variant manifest once via the /upstream proxy
				// (which causes origin/vod-module work) and only include the
				// variant in the master if the returned manifest includes
				// EXT-X-TARGETDURATION. Retry once if missing.
				$final_variants = [];
				// Perform parallel GETs for manifests for variants that passed HEAD
				$manifest_urls = [];
				foreach ($available as $suf => $meta) {
					// try upstream then edge but prefer upstream for warming
					$manifest_urls[$suf . '_up'] = $scheme . '://' . $host . '/upstream' . $meta['manifest'];
					$manifest_urls[$suf . '_edge'] = $scheme . '://' . $host . $meta['manifest'];
				}
				if (count($manifest_urls) > 0) {
					$bodies = rc_curl_multi_get($manifest_urls, 3);
					// Accept a variant if either upstream or edge manifest contains target duration
					foreach ($available as $suf => $meta) {
						$up_key = $suf . '_up'; $edge_key = $suf . '_edge';
						$body_up = $bodies[$up_key] ?? false;
						$body_edge = $bodies[$edge_key] ?? false;
						if (($body_up !== false && stripos($body_up, 'EXT-X-TARGETDURATION') !== false) ||
							($body_edge !== false && stripos($body_edge, 'EXT-X-TARGETDURATION') !== false)) {
							$final_variants[] = $meta;
						}
					}
				}

				if (count($final_variants) > 0) {
					// Master-level cache: avoid rebuilding master on high request rates.
					$master_cache_key = 'master:' . md5($manifest);
					$cached_master = rc_cache_get($master_cache_key);
					if ($cached_master !== false) {
						header('Content-Type: application/vnd.apple.mpegurl');
						// Provide Content-Length so downstream proxies/clients can see size
						header('Content-Length: ' . strlen($cached_master));
						echo $cached_master;
						exit;
					}

					// Acquire a short lock to prevent stampede. If we cannot acquire,
					// briefly wait and try to read the cached master again.
					$lock_key = 'lock:' . md5($manifest);
					$got_lock = rc_acquire_lock($lock_key, 5);
					if (!$got_lock) {
						// Wait a short time for the lock-holder to populate cache
						usleep(200000); // 200ms
						$cached_master = rc_cache_get($master_cache_key);
						if ($cached_master !== false) {
							header('Content-Type: application/vnd.apple.mpegurl');
							echo $cached_master;
							exit;
						}
						// If still no cache, attempt to acquire lock again but don't block long
						$got_lock = rc_acquire_lock($lock_key, 2);
					}

					// Build the master playlist now while holding the lock (if we have it).
					$master = "#EXTM3U\n#EXT-X-VERSION:3\n";
					foreach ($final_variants as $v) {
						$master .= "#EXT-X-STREAM-INF:BANDWIDTH={$v['bw']},RESOLUTION={$v['res']}\n";
						$master .= $v['manifest'] . "\n";
					}

					// Cache the generated master for a short duration to reduce probes.
					rc_cache_set($master_cache_key, $master, 30);
					if ($got_lock) rc_release_lock($lock_key);
					header('Content-Type: application/vnd.apple.mpegurl');
					// Provide Content-Length so downstream proxies/clients can see size
					header('Content-Length: ' . strlen($master));
					echo $master;
					exit;
				}
			}
		}

		// Preserve original query string and ensure _validator remains to avoid loops
		$qs = $_SERVER['QUERY_STRING'] ?? '';
		if (strpos($qs, '_validator=1') === false) {
			$qs = ($qs === '' ? '' : ($qs . '&')) . '_validator=1';
		}

		$internal = $manifest . ($qs ? ('?' . $qs) : '');
		error_log("[remote_control] validator: internal X-Accel-Redirect to manifest: " . $internal);
		// Internal redirect into the edge's /hls/ location so the nginx-vod-module repackages the mp4 into an m3u8
		header('X-Accel-Redirect: ' . $internal);
		exit;
	}

	// Return a 302 redirect to the PHP validator URL for the manifest.
	// Build a remote_control.php URL with original params but file set to the manifest
	// and cv4 recomputed for the manifest path so the validator accepts it.
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

	// Prepare params: use original request params but replace file and cv4
	$params = $_REQUEST;
	// Ensure file is the manifest path
	$params['file'] = $target_file;
	// Compute new cv4 for this manifest path
	$params['cv4'] = md5($target_file . $config['cv']);
	// Mark this as the validator round-trip so the next request doesn't redirect again
	$params['_validator'] = '1';

	// Build query preserving RFC3986 encoding
	$query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	$validator_url = $scheme . '://' . $host . $_SERVER['PHP_SELF'] . '?' . $query;
	// DEBUG: log the validator URL we will redirect the client to
	error_log("[remote_control] redirecting client to validator_url: " . $validator_url);
	header('Location: ' . $validator_url, true, 302);
	exit;
}
