#!/usr/bin/env php
<?php

function packJSapi($data, $input_file) {
	$postdata = array(
		'output_info' => 'compiled_code',
		//'output_info' => 'errors',
		'warning_level' => 'VERBOSE',
		'output_format' => 'text',
		'compilation_level' => 'SIMPLE_OPTIMIZATIONS',
		'js_code' => is_string($input_file) ? file_get_contents($input_file) : $data,
	);

	$opts = array('http' =>
	    array(
	        'method'  => 'POST',
	        'header'  => 'Content-type: application/x-www-form-urlencoded',
		'content' => preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', http_build_query($postdata))
	    )
	);
	$context  = stream_context_create($opts);

	$compressed = file_get_contents('http://closure-compiler.appspot.com/compile', false, $context);
	return $compressed;
}

function packJScompiler($data, $input_file = null, $dst_file = null) {
  global $basename;
  if (is_string($input_file)) {
    $stderr = dirname($input_file) . '/stderr.log';
  } else {
    $stderr = $basename . '/stderr.log';
  }
  $descriptorspec = array(
			  0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
			  1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
			  2 => array("file", $stderr, "a") // stderr is a file to write to
			  );
  $opts = array(
		'--compilation_level', 'SIMPLE',
		'--warning_level', 'DEFAULT',
		);
  if (is_string($input_file)) {
    $opts = array_merge($opts, array('--js', $input_file));
    $descriptorspec[0] = array("file", "/dev/null", "r");
    if (is_string($dst_file)) {
      $srcmap_file = preg_replace("#\.js$#", ".map", $dst_file);
      $opts = array_merge($opts, array('--js_output_file', $dst_file,
				       '--create_source_map', $srcmap_file,
				       '--source_map_location_mapping',
				       '\''. dirname($input_file) .'/|\'',
				       '--output_wrapper',
				       "'%output%\n//# sourceMappingURL=".basename($srcmap_file)."'"
				       )
			  );
    }
  }
  $cmdline = array_merge(explode(' ', "java -jar $basename/compiler.jar"), $opts);
  file_put_contents($stderr, implode(' ', array_merge($cmdline, array("\n\n"))), FILE_APPEND);
  $process = proc_open(implode(' ', $cmdline), $descriptorspec, $pipes);
  if (is_resource($process)) {
    if (!is_string($input_file)) {
      fwrite($pipes[0], $data);
      fclose($pipes[0]);
    }
    if (!is_string($dst_file)) {
      $compressed = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
    } else {
      $compressed = $dst_file;
    }
    $return_value = proc_close($process);
  }
  return $compressed;
}
function packJS($data, $filename) {
  $closure_input_filename = preg_replace("#\.min#", "", $filename);
  file_put_contents($closure_input_filename, $data);
  return call_user_func('packJS' . (CLOSURE_SERVICE ? 'api' : 'compiler'),
			null,
			$closure_input_filename,
			(CLOSURE_SERVICE ? null : $filename));
}

$basename = dirname(dirname(__FILE__));
$configraw = file_get_contents($basename . '/etc/config.js');
$config = json_decode($configraw, true);


define("CLOSURE_SERVICE", (isset($config['closure_service']) ? (bool) $config['closure_service'] : true));
date_default_timezone_set((isset($config['timezone']) ? $config['timezone'] : "UTC"));

$version = $config['version'];
// if (count($argv) >= 2) $version = $argv[1];

echo 'Version: ' . $version . "\n";


$date = date('Y-m-d H:i');

// $base = './discojuice/www/discojuice/';
$sourcebase = $basename . '/discojuice/';

$files = array(
	'discojuice.misc.js',
	'discojuice.ui.js',
	'discojuice.control.js',
	'discojuice.hosted.js',
);
$data = '';
foreach($files AS $file) {
	$data .= file_get_contents($sourcebase . $file);
}
$data .= "\n" . 'DiscoJuice.Version = "' . $version . ' (' . $date . ')";' . "\n";


$langmeta = json_decode(file_get_contents($sourcebase . 'languages.json'), TRUE);
foreach($langmeta AS $lang) {
	
	$ldata = $data . file_get_contents($sourcebase . 'discojuice.dict.' . $lang . '.js');
	$filename = $basename . '/builds/discojuice-' .  $version . '.' . $lang . '.min.js';
	echo "Packing " . $filename . "\n";
	$compressed = packJS($ldata, $filename);
	if ($compressed != $filename) {
	  file_put_contents($filename, $compressed);
	}
}


$ldata = file_get_contents($sourcebase . 'idpdiscovery.js');
// echo $ldata;
$filename = $basename . '/builds/idpdiscovery-' .  $version . '.min.js';
echo "Packing " . $filename . "\n";
$compressed = packJS($ldata, $filename);
if ($compressed != $filename) {
  file_put_contents($filename, $compressed);
}

