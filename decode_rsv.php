<?php
require(__DIR__.'/parse_mp4.php');

// decode C0373.RSV file
$rec = new SonyRecovery('C0373.RSV');
//$rec = new SonyRecovery('C0372.MP4', 0x20000);

// generate recovery from valid mp4 file
$rec->recover('/drobo/C0373_fixed.MP4', 'C0372.MP4');

class SonyRecovery {
	private $fp;
	private $kkad = '';
	private $frames = [];

	private $all_frames = [];
	private $offsets = ['video' => [], 'audio' => [], 'rtmd' => []];
	private $endpos;
	private $load_offset = 0;

	public function __construct($filename, $seek = 0) {
		// recover a .RSV file, or load data from a generated .mp4, based on some assumptions
		$this->fp = fopen($filename, 'r');
		if (!$this->fp) throw new \Exception('Failed to open file');

		if ($seek) {
			$this->load_offset = $seek;
			fseek($this->fp, $seek);
		}

		$this->parseFile();
	}

	public function parseFile() {
		echo 'Parsing file...'."\n";

		$info = fstat($this->fp);
		$start = microtime(true);
		$pos = 0;

		while(true) {
			$this->endpos = $pos - $this->load_offset;
			$pos = ftell($this->fp);
			/* if ($pos > 5*1024*1024*1024) {
				$this->endpos = $pos;
				break; // BREAK at 500MB for testing XXX
			} // */

			echo "\r".'Position = '.dechex($pos); // 00637a41,
			// rtmd chunk
			try {
				for($i = 0; $i < 12; $i++) $this->parseRtmdFrame();
				$this->processKkad();
			} catch(\Exception $e) {
				if ($e->getMessage() == 'EOF') {
					break;
				}
				throw $e;
			}

			$this->offsets['rtmd'][] = $pos - $this->load_offset;
			$this->offsets['video'][] = ftell($this->fp) - $this->load_offset;
			$this->handleVideoFrames();
			$this->offsets['audio'][] = ftell($this->fp) - $this->load_offset;
			$this->handleAudioFrames();
		}
		echo "\n";
		$time = microtime(true)-$start;

		printf('Parsed %d frames (%s at 25fps) in %01.2f ms'."\n", count($this->all_frames), MP4::formatDuration(count($this->all_frames)/25), $time*1000);
	}

	public function recover($out, $valid) {
		$mp4 = new MP4($valid);

		// we need to override the following atoms
		$mp4->remove('/moov/trak/0/mdia/minf/stbl/stss'); // not needed?

		// let's override:
		// - /moov/trak/2/mdia/minf/stbl/stco (rtmd, from offsets)
		// - /moov/trak/1/mdia/minf/stbl/stco (audio, from offsets)
		// - /moov/trak/0/mdia/minf/stbl/stsz (video, from all_frames)
		// - /moov/trak/0/mdia/minf/stbl/stco (video, from offsets)

		$offt = $mp4->get('/mdat')->offset; // position of mdat data start

		$mp4->override('/mdat', $this->fp, $this->load_offset, $this->endpos);

		$tracks = [
			'rtmd' => 2,
			'audio' => 1,
			'video' => 0,
		];

		foreach($tracks as $trk => $id) {
			$need64 = false;
			$stco = pack('NN', 0, count($this->offsets[$trk]));
			foreach($this->offsets[$trk] as $v) {
				if ($v+$offt > 0x7fffffff) {
					$need64 = true;
					break;
				}
				$stco .= pack('N', $v+$offt);
			}
			$mp4->override("/moov/trak/$id/mdia/minf/stbl/stco", $stco);

			if ($need64) {
				// build 64 bits track
				$stco = pack('NN', 0, count($this->offsets[$trk]));
				foreach($this->offsets[$trk] as $v) $stco .= pack('J', $v+$offt);
				$mp4->override("/moov/trak/$id/mdia/minf/stbl/co64", $stco);
			}
		}

		// generate stsz
		$video_stsz = pack('NNN', 0, 0, count($this->all_frames));
		foreach($this->all_frames as $v) $video_stsz .= pack('N', $v['len']);
		$mp4->override('/moov/trak/0/mdia/minf/stbl/stsz', $video_stsz);

		// generate ctts
		// /moov/trak/0/mdia/minf/stbl/ctts
		// 3000*1, 0*2, 3000*1, 0*2, etc... (pattern repeats)
		$ctts_count = (int)ceil(count($this->all_frames)/3);
		$video_ctts = pack('NN', 0, $ctts_count*2);
		for($i = 0; $i < $ctts_count; $i++) {
			$video_ctts .= pack('NNNN', 1, 3000, 2, 0); // 3000*1, 0*2
		}
		$mp4->override('/moov/trak/0/mdia/minf/stbl/ctts', $video_ctts);

		$mp4->setDuration(count($this->all_frames), 25);

		echo 'Generating new MP4 ...'."\n";
		$mp4->output($out);

		new MP4($out, true);
	}

	public function handleVideoFrames() {
		$len = 0;
		foreach($this->frames as $frame) {
			$this->all_frames[] = $frame;
			$len += $frame['len'];
		}

		#echo 'Video chunk size = 0x'.dechex($len)."\n";

		// advance file
		fseek($this->fp, $len, SEEK_CUR);
	}

	public function handleAudioFrames() {
		$len = 23040*4; // samples per frame * bytes per sample

		#echo 'Audio chunk size = 0x'.dechex($len)."\n";

		// advance file
		fseek($this->fp, $len, SEEK_CUR);
	}

	public function processKkad() {
		static $id = 0;
		$id += 1;

		$kkad = $this->kkad;
		$this->kkad = '';

		if (strlen($kkad) == 0) throw new \Exception('EOF');

		#file_put_contents('kkad_full_'.$id.'.bin', $kkad);

		#echo 'Processing KKAD len='.dechex(strlen($kkad))."\n";

		// starting offset 0x270 we have video frame info
		// offset is 0x270 for first kkad, 0x2c for the next ones
		// sometimes it's different ...
		$start_pos = $id==1?0x270:0x2c;
		// try to guess start pos
		$start_pos_guess = strpos($kkad, "\x00\x08\x04\x02");
		if ($start_pos_guess > 0) $start_pos = $start_pos_guess - 4;

		$video_frames = substr($kkad, $start_pos);
		$res = [];

		#var_dump(strlen($video_frames), $start_pos, 0x270);

		for($i = 0; $i < 12; $i++) {
			$frame = substr($video_frames, $i*32, 32);
			list(,$v1, $v2, $v3, $v4, $tc1, $tc2, $len, $v5) = unpack('V8', $frame);
			if (($len < 0x1000) || ($len > 16*1024*1024)) throw new \Exception('Invalid kkad, invalid frame size');
			#echo 'Frame #'.$i.' size='.dechex($len). ' v1='.dechex($v1).' v2='.dechex($v2)."\n";

			$res[] = ['len' => $len];
		}
		$this->frames = $res;
	}

	public function parseRtmdFrame() {
		// parse a single rtmd frame
		$s = fread($this->fp, 11264);
		if (strlen($s) != 11264) throw new \Exception('EOF');

		// each sample starts with following: 2 bytes header len, 0x0100, MXF KLV len, padding len (big endian)
		list(,$hlen) = unpack('n', substr($s, 0, 2));
		$header = substr($s, 0, $hlen);

		list(,$hlen,$code,$mxf_len,$pad_len) = unpack('n4', substr($s, 0, 8));

		//echo 'RTMD HEADER='.chunk_split(bin2hex($header), 4, ' ')."\n";

		// skip mxf keyval data (don't care)

		$this->parseRtmdFooter(substr($s, $hlen+$mxf_len));
	}

	public function parseRtmdFooter($rtmd_data) {
		// rtmd data is made of blocks starting with f0 10|20 <uint16 len>, last block starting with ff ff (padding+end)
		while(strlen($rtmd_data) > 0) {
			list(, $type, $len) = unpack('n2', substr($rtmd_data, 0, 4));
			$sub = substr($rtmd_data, 4, $len);
			$rtmd_data = (string)substr($rtmd_data, $len+4);

			//echo ' * Sub RTMD: type='.dechex($type).' len='.$len."\n";

			switch($type) {
				case 0xf020:
					// got a kkad header, probably
					$this->parseRtmdAtoms($sub);
					break;
				default:
					break;
			}
		}
	}

	public function parseRtmdAtoms($sub) {
		while(strlen($sub) > 0) {
			list(,$len) = unpack('N', substr($sub, 0, 4));
			$type = substr($sub, 4, 4);

			if ($len > strlen($sub)) throw new \Exception('Data missing');

			$mine = substr($sub, 8, $len-8);
			$sub = (string)substr($sub, $len+8);

			$this->handleRtmdAtom($type, $mine);
		}
	}

	public function handleRtmdAtom($type, $data) {
		switch($type) {
			case 'kkad':
				return $this->handleRtmdKkad($data);
		}
		echo 'UNKNOWN ATOM '.$type.' = '.bin2hex($data)."\n";
	}

	public function handleRtmdKkad($data) {
		if (substr($data, 0, 8) == str_repeat("\0", 8)) {
			#echo 'KKAD append '.strlen($data).' bytes'."\n";
			// cont.
			if ($this->kkad == '') throw new \Exception('continuation on empty kkad');
			$this->kkad .= substr($data, 8);
		} else {
			#echo 'KKAD set '.strlen($data).' bytes'."\n";
			if ($this->kkad != '') throw new \Exception('unhandled kkad data on new');
			$this->kkad = $data;
		}
	}
}
