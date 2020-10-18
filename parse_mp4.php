<?php

// http://xhelmboyx.tripod.com/formats/mp4-layout.txt
// https://github.com/abema/go-mp4

new MP4('C0372.MP4');

class MP4 {
	private $fp;
	private $type = '';

	public function __construct($file) {
		$this->fp = fopen($file, 'r');
		if (!$this->fp) throw new \Exception('Failed to open file');

		$info = fstat($this->fp);

		$this->_parseRegion(0, $info['size'], 0);
	}

	public function _parseRegion($start, $len, $depth) {
		$end = $start+$len;

		while(($start+8) < $end) {
			$len = $this->_parseAtom($start, $depth);
			$start += $len;
		}
	}

	public function _parseAtom($offset, $depth) {
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

		echo str_repeat('  ', $depth).'ATOM '.$type.' at 0x'.dechex($offset).' len '.$len.' ends 0x'.dechex($offset+$len)."\n";

		$func = '_parse_atom_'.$type;
		if (is_callable([$this, $func])) {
			$this->$func($offset+$headerlen, $len-$headerlen, $depth+1);
		} else if (in_array($type, $containers)) {
			// recurse
			$this->_parseRegion($offset+$headerlen, $len-$headerlen, $depth+1);
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

		echo str_repeat('  ', $depth).'File of type '.$this->type.' version '.bin2hex($version).', complying with:'."\n";
		foreach($type_compat as $type) {
			if (isset($brand_names[$type])) {
				echo str_repeat('  ', $depth).' * '.$brand_names[$type]." ($type)\n";
			} else {
				echo str_repeat('  ', $depth).' * '.$type."\n";
			}
		}
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
		echo str_repeat('  ', $depth), 'CODEC = '.$codec." (dref index=$dref_idx)\n";
		//var_dump(bin2hex($data));
	}

	public function _parse_atom_stco($offset, $len, $depth) {
		fseek($this->fp, $offset);
		$data = fread($this->fp, $len);
		list(, $flags, $count) = unpack('N2', substr($data, 0, 8));

		if (($flags != 0) || ($count*4+8 != $len))
			throw new \Exception('invalid stco atom');

		$data = chunk_split(bin2hex(substr($data, 8)), 8, ', ');

		echo str_repeat('  ', $depth), 'Data offsets for '.$count.' chunks = '.$data."\n";
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
			echo str_repeat('  ', $depth), 'Sample to chunk: first chunk '.$first_chunk.", samples $samples, description $desc\n";
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

			echo str_repeat('  ', $depth), 'Sample sizes = '.$data."\n";
		} else {
			echo str_repeat('  ', $depth), 'All sample size = '.$size.' (pad='.dechex($count).")\n";
		}
	}
}
