<?php
error_reporting(E_ALL);

// http://xhelmboyx.tripod.com/formats/mp4-layout.txt
// https://github.com/abema/go-mp4

if ($_SERVER['PHP_SELF'] == basename(__FILE__)) {
	// test
	//new MP4('/drobo/C0373_fixed.MP4', true);
	//new MP4('C0372.MP4', true);
	$f = new MP4('C0373_fixed.MP4', true);
	#$f->override('/mdat', '');
	#$f->output('C0373_fixed_no_mdat.MP4');
}

class MP4Atom {
	public $fp;
	public $offset;
	public $len;
	public $name;

	public function __construct($name) {
		$this->name = $name;
	}

	public function get() {
		fseek($this->fp, $this->offset);
		return fread($this->fp, $this->len);
	}

	public function set($val) {
		$fp = fopen('php://temp', 'w+');
		fwrite($fp, $val);
		$this->fp = $fp;
		$this->offset = 0;
		$this->len = strlen($val);
		return true;
	}
}

class MP4 {
	private $fp;
	private $type = '';
	private $parts = [];
	private $track_num = 0;
	private $verbose = false;

	public function __construct($file, $verbose = false) {
		$this->verbose = $verbose;
		$this->fp = fopen($file, 'r');
		if (!$this->fp) throw new \Exception('Failed to open file');

		$info = fstat($this->fp);

		$this->_parseRegion(0, $info['size'], 0, '/');
	}

	public function get($atom) {
		if (!isset($this->parts[$atom])) return NULL;
		return $this->parts[$atom];
	}

	public function remove($atom) {
		unset($this->parts[$atom]);
	}

	public function override($atom, $fp, $offset = 0, $len = -1) {
		if (is_string($fp)) {
			$data = $fp;
			$fp = fopen('php://temp', 'w+');
			fwrite($fp, $data);
			rewind($fp);

			if ($len == -1) $len = strlen($data);
		}

		if ($len == -1) {
			$stat = fstat($fp);
			$len = $stat['size'] - $offset;
		}

		$o = new MP4Atom($atom);
		$o->fp = $fp;
		$o->offset = $offset;
		$o->len = $len;

		$this->parts[$atom] = $o;
	}

	public function output($target) {
		$out = fopen($target, 'w');
		$parts = $this->parts;

		// build recursive version of parts
		$tree = [];
		foreach($parts as $v) {
			$pos = &$tree;
			$k = explode('/', $v->name);
			$final = array_pop($k);

			foreach($k as $sk) {
				if ($sk != '') {
					if (!isset($pos[$sk])) $pos[$sk] = [];
					$pos = &$pos[$sk];
				}
			}
			$pos[$final] = $v;
		}

		foreach($tree as $k => $v) {
			if ($k == 'mdat') {
				echo 'Writing mdat: ';
				fwrite($out, pack('N', 1).$k.pack('J', $v->len+16));
				fseek($v->fp, $v->offset);
				$len = $v->len;
				$total = 0;
				$target = ftell($out)+$len;

				while($len > 0) {
					echo "\r".'Writing mdat: '.sprintf('%01.2f%% ...', $total/$v->len*100);
					$tlen = min($len, 32*1024*1024); // 32MB
					$copied = stream_copy_to_stream($v->fp, $out, $tlen);
					fflush($out);
					$len -= $copied;
					$total += $copied;
				}
				fseek($out, $target);
				echo "\r".'Writing mdat: 100% !!   '."\n";
				continue;
			}
			$data = $this->_renderAtom($k, $v);
			fwrite($out, $data);
		}
		fclose($out);
	}

	public function _renderAtom($type, $child) {
		echo 'Writing '.$type.' ...'."\n";
		if (is_array($child)) {
			$data = '';
			$skip = false;
			foreach($child as $k => $v) {
				if (is_numeric($k)) {
					$skip = true;
					$k = $type;
				}
				$data .= $this->_renderAtom($k, $v);
			}
			if ($skip) return $data;

			return pack('N', strlen($data)+8).$type.$data;
		}

		// render one specific atom
		$fp = $child->fp;
		fseek($fp, $child->offset);
		$data = fread($fp, $child->len);

		return pack('N', strlen($data)+8).$type.$data;
	}

	public function _parseRegion($start, $len, $depth, $path) {
		$end = $start+$len;

		while(($start+8) < $end) {
			$len = $this->_parseAtom($start, $depth, $path);
			$start += $len;
		}
	}

	public function _parseAtom($offset, $depth, $path) {
		fseek($this->fp, $offset);
		$len = fread($this->fp, 4);
		if (strlen($len) != 4) throw new \Exception('Unable to read len');
		$type = fread($this->fp, 4);

		$headerlen = 8;

		list(,$len) = unpack('N', $len);
		if ($len == 0) {
			// len is EOF-$offset
			$info = fstat($this->fp);
			$len = $info['size'] - $offset;
		} else if ($len == 1) {
			// len is stored as 64bit int
			$headerlen = 16;
			$len = fread($this->fp, 8);
			if (strlen($len) != 8) throw new \Exception('Unable to read len64');
			list(,$len) = unpack('J', $len);
		}
		if ($len < $headerlen) throw new \Exception('Invalid length');

		// those atom types are containers, they have atom children
		$containers = ['moov', 'mdia', 'minf', 'trak', 'udta', 'ilst', 'mdra', 'cmov', 'rmra', 'rmda', 'clip', 'matt', 'edts', 'minf', 'dinf', 'stbl', 'sinf', 'udta'];

		$me = $path . $type;
		if ($me == '/moov/trak') {
			$tn = $this->track_num++;
			$me .= '/'.$tn;
		}
		if (!in_array($type, $containers)) {
			if (isset($this->parts[$me])) throw new \Exception('duplicate path '.$me);

			$atom = new MP4Atom($me);
			$atom->fp = $this->fp;
			$atom->offset = $offset+$headerlen;
			$atom->len = $len-$headerlen;

			$this->parts[$me] = $atom;
		}

		if ($this->verbose) echo str_repeat('  ', $depth).'ATOM '.$me.' at 0x'.dechex($offset).' len '.$len.' ends 0x'.dechex($offset+$len)."\n";

		$func = '_parse_atom_'.$type;
		if (is_callable([$this, $func])) {
			$this->$func($offset+$headerlen, $len-$headerlen, $depth+1, $path . $type);
		} else if (in_array($type, $containers)) {
			// recurse
			$this->_parseRegion($offset+$headerlen, $len-$headerlen, $depth+1, $me . '/');
		}

		return $len;
	}

	public function _parse_atom_ftyp($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		$this->type = substr($data, 0, 4);
		$version = substr($data, 4, 4);
		$data = substr($data, 8);
		$type_compat = [];
		while(strlen($data) >= 4) {
			$type_compat[] = substr($data, 0, 4);
			$data = (string)substr($data, 4);
		}

		$brand_names = [
			'isom' => 'ISO 14496-1 Base Media',
			'iso2' => 'ISO 14496-12 Base Media',
			'mp41' => 'ISO 14496-1 vers. 1',
			'mp42' => 'ISO 14496-1 vers. 2',
			'qt  ' => 'quicktime movie',
			'avc1' => 'JVT AVC',
			'3gp'  => '3G MP4 profile', // + ASCII value
			'mmp4' => '3G Mobile MP4',
			'M4A ' => 'Apple AAC audio w/ iTunes info',
			'M4P ' => 'AES encrypted audio',
			'M4B ' => 'Apple audio w/ iTunes position',
			'mp71' => 'ISO 14496-12 MPEG-7 meta data',
		];

		if ($this->verbose) echo str_repeat('  ', $depth).'File of type '.$this->type.' version '.bin2hex($version).', complying with:'."\n";
		foreach($type_compat as $type) {
			if (isset($brand_names[$type])) {
				if ($this->verbose) echo str_repeat('  ', $depth).' * '.$brand_names[$type]." ($type)\n";
			} else {
				if ($this->verbose) echo str_repeat('  ', $depth).' * '.$type."\n";
			}
		}
	}

	public function _parse_atom_elst($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags) = unpack('N', substr($data, 0, 4));
		$version = ($flags >> 24) & 0xff;
		if ($version != 0) throw new \Exception('unsupported elst');

		list(, $edit_count, $time_length, $start_time) = unpack('N3', substr($data, 4, 12));

		if ($this->verbose) echo str_repeat('  ', $depth).'Edit Count: '.$edit_count.' Time Length: '.self::formatDuration($time_length/90000).' Start Time: '.self::formatDuration($start_time/90000)."\n";
	}

	public function _parse_atom_mdhd($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags) = unpack('N', substr($data, 0, 4));
		$version = ($flags >> 24) & 0xff;
		if ($version != 0) throw new \Exception('unsupported mdhd');

		// version 0
		// created/modified = long unsigned value in seconds since beginning 1904 to 2040
		list(, $flags, $created, $modified, $time_scale, $time_duration) = unpack('N5', $data);

		if ($this->verbose) echo str_repeat('  ', $depth).'Created: '.$created.' Modified: '.$modified.' Time: '.self::formatDuration($time_duration/$time_scale)."\n";
	}

	public function _parse_atom_stsd($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags, $count, $desc_len) = unpack('N3', substr($data, 0, 12));
		if (($flags != 0) || ($count != 1)) throw new \Exception('unsupported data in stsd');

		if ($desc_len != $len-8) throw new \Exception('extra data in stsd');
		$data = substr($data, 12);

		$codec = substr($data, 0, 4);
		// 6 bytes zero
		$dref_idx = bin2hex(substr($data, 4+6, 2));
		$data = (string)substr($data, 4+6+2);

		switch($codec) {
			case 'mp4v':
			case 'avc1':
			case 'encv':
			case 's263':
				// video info
				break;
			case 'mp4a':
			case 'enca':
			case 'samr':
			case 'sawb':
				// audio info
				break;
		}

		// data after tihs depends on the value of codec
		if ($this->verbose) echo str_repeat('  ', $depth), 'CODEC = '.$codec." (dref index=$dref_idx)\n";
		//var_dump(bin2hex($data));
	}

	public function _parse_atom_stco($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);
		list(, $flags, $count) = unpack('N2', substr($data, 0, 8));

		if (($flags != 0) || ($count*4+8 != $len))
			throw new \Exception('invalid stco atom');

		$data = chunk_split(bin2hex(substr($data, 8)), 8, ', ');

		#echo str_repeat('  ', $depth), 'Data offsets for '.$count.' chunks = '.$data."\n";
		if ($this->verbose) echo str_repeat('  ', $depth), "Found $count data offsets\n";
	}

	public function _parse_atom_stsc($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);
		list(, $flags, $count) = unpack('N2', substr($data, 0, 8));

		if (($flags != 0) || ($count*12+8 != $len))
			throw new \Exception('invalid stsc atom');

		$data = substr($data, 8);

		for($i = 0; $i < $count; $i++) {
			$sub = substr($data, $i*12, 12);
			list(, $first_chunk, $samples, $desc) = unpack('N3', $sub);
			if ($this->verbose) echo str_repeat('  ', $depth), 'Sample to chunk: first chunk '.$first_chunk.", samples $samples, description $desc\n";
		}
	}

	public function _parse_atom_stsz($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags, $size, $count) = unpack('N3', substr($data, 0, 12));

		$real_count = ($size == 0) ? $count : 0;

		if (($flags != 0) || ($real_count*4+12 != $len))
			throw new \Exception('Invalid stsz table');

		if ($real_count > 0) {
			$data = chunk_split(bin2hex(substr($data, 12)), 8, ', ');

			//echo str_repeat('  ', $depth), 'Sample sizes = '.$data."\n";
			if ($this->verbose) echo str_repeat('  ', $depth), 'Found '.$real_count.' sample sizes'."\n";
		} else {
			if ($this->verbose) echo str_repeat('  ', $depth), 'All sample size = '.$size.' (frames='.$count.")\n";
		}
	}

	public function _parse_atom_stss($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags, $count) = unpack('N2', substr($data, 0, 8));

		// TODO

		//var_dump($flags, $count);
	}

	public function _parse_atom_stts($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags, $count) = unpack('N2', substr($data, 0, 8));

		if (($flags != 0) || ($count*8+8 != $len)) throw new \Exception('invalid stts atom');

		$info = [];
		for($i = 0; $i < $count; $i++) {
			list(,$cnt, $duration) = unpack('N2', substr($data, 8+$i*8, 8));
			$info[] = $cnt.':'.$duration;
		}

		if ($this->verbose) echo str_repeat('  ', $depth).'count:duration: '.implode(', ', $info)."\n";
	}

	public function _parse_atom_ctts($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);

		list(, $flags, $count) = unpack('N2', substr($data, 0, 8));
		if (($flags != 0) || ($count*8+8 != $len))
			throw new \Exception('Invalid ctts atom');

		if ($this->verbose) echo str_repeat('  ', $depth). 'Found '.$count.' presentation samples'."\n";

		$info = [];
		$total = 0;
		for($i = 0; $i < $count; $i++) {
			list(,$c,$offset) = unpack('N2', substr($data, 8+$i*8, 8));
			//$info[] = '0x'.dechex($offset).'*'.$c;
			$info[] = $offset.'*'.$c;
			$total += $c;
		}
		if ($this->verbose) echo str_repeat('  ', $depth). 'total='.$total."\n";//.' '.implode(', ', $info)."\n";
	}

	public static function formatDuration($secs) {
		// split in hour/min/sec
		$hours = 0;
		$mins = 0;

		if ($secs > 3600) {
			$hours = (int)floor($secs/3600);
			$secs -= $hours*3600;
		}
		if ($secs > 60) {
			$mins = (int)floor($secs/60);
			$secs -= $mins*60;
		}
		return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
	}

	public function setDuration($val, $div) {
		// set duration to $val/$div seconds
		$mvhd = $this->get('/moov/mvhd');

		$data = $mvhd->get();
		list(,$flags) = unpack('N', substr($data, 0, 4));
		if ($flags != 0) throw new \Exception('unsupported flags (v1?)');

		list(, $time_unit, $duration) = unpack('N2', substr($data, 12, 8));

		// compute new duration
		$new_dur = (int)round($val * $time_unit / $div);

		$data = substr_replace($data, pack('N', $new_dur), 16, 4);
		$mvhd->set($data);

		// update tracks
		for($i = 0; isset($this->parts["/moov/trak/$i/tkhd"]); $i++) {
			$tkhd = $this->get("/moov/trak/$i/tkhd");
			$data = $tkhd->get();

			list(,$flags, $created, $modified, $id) = unpack('N4', substr($data, 0, 16));
			if ((($flags>>24) & 0xff) != 0) throw new \Exception('unsupported version (v1?)');

			// get duration
			list(,$duration) = unpack('N', substr($data, 20, 4));
			echo 'Setting track '.$id.' duration from '.self::formatDuration($duration/$time_unit).' to '.self::formatDuration($new_dur/$time_unit)."\n";

			// set new duration
			$data = substr_replace($data, pack('N', $new_dur), 20, 4);
			$tkhd->set($data);

			$elst = $this->get("/moov/trak/$i/edts/elst");
			if ($elst) {
				$data = $elst->get();
				list(,$flags) = unpack('N', $data);
				if ($flags == 0) {
					list(,$duration) = unpack('N', substr($data, 8, 4));
					echo 'Setting track '.$id.' elst duration from '.self::formatDuration($duration/$time_unit).' to '.self::formatDuration($new_dur/$time_unit)."\n";
					$data = substr_replace($data, pack('N', $new_dur), 8, 4);
					$elst->set($data);
				}
			}

			$mdhd = $this->get("/moov/trak/$i/mdia/mdhd");
			if ($mdhd) {
				$data = $mdhd->get();
				list(,$flags) = unpack('N', $data);
				if ($flags == 0) {
					list(,$time_scale, $duration) = unpack('N2', substr($data, 12, 8));
					$mdhd_dur = (int)round($val * $time_scale / $div);
					echo 'Setting track '.$id.' mdhd duration from '.self::formatDuration($duration/$time_scale).' to '.self::formatDuration($mdhd_dur/$time_scale)."\n";
					$data = substr_replace($data, pack('N', $mdhd_dur), 16, 4);

					list(,$time_scale, $duration) = unpack('N2', substr($data, 12, 8));
					$mdhd->set($data);
				}
			}
		}

		return true;
	}
}
