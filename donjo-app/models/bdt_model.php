<?php class Bdt_Model extends CI_Model{

	function __construct(){
		parent::__construct();

		$this->kolom = array(
			'id_rtm' => 2,
			'nama' => 80,
			'nik' => 81,
			'rtm_level' => 82
		);
	}

	private function file_import_valid() {
		// error 1 = UPLOAD_ERR_INI_SIZE; lihat Upload.php
		// TODO: pakai cara upload yg disediakan Codeigniter
		if ($_FILES['bdt']['error'] == 1) {
			$upload_mb = max_upload();
			$_SESSION['error_msg'].= " -> Ukuran file melebihi batas " . $upload_mb . " MB";
			$_SESSION['success']=-1;
			return false;
		}

	  $tipe_file = TipeFile($_FILES['bdt']);
		$mime_type_excel = array("application/vnd.ms-excel", "application/octet-stream");
		if(!in_array($tipe_file, $mime_type_excel)){
			$_SESSION['error_msg'].= " -> Jenis file salah: " . $tipe_file;
			$_SESSION['success']=-1;
			return false;
		}

		return true;
	}

	/*
	 * Impor data BDT 2015 ke dalam analisis
	*/
	function impor(){
		$_SESSION['error_msg'] = '';
		$_SESSION['success'] = 1;
		if ($this->file_import_valid() == false) {
			return;
		}

		$data = new Spreadsheet_Excel_Reader($_FILES['bdt']['tmp_name']);

		// membaca jumlah baris dari data excel
		$baris = $data->rowcount($sheet_index=0);
		$baris_pertama = $this->cari_baris_pertama($data, $baris);
		if ($baris_pertama <= 0) {
			$_SESSION['error_msg'].= " -> Tidak ada data";
			$_SESSION['success']=-1;
			return;
		}
		$gagal = 0;
		$data_sheet = $data->sheets[0]['cells'];
		for($i=$baris_pertama; $i<$baris; $i++){
			if (!$this->tulis_rtm($data_sheet[$i])) $gagal++;
		}
		echo "<br>JUMLAH GAGAL : $gagal</br>";
		echo "<a href='".site_url()."analisis_respon'>LANJUT</a>";
	}

	private function cari_baris_pertama($data, $baris) {
		if ($baris <=1 )
			return 0;

		$ada_baris = false;
		// Baris pertama baris judul kolom
		for ($i=2; $i<=$baris; $i++){
			// Baris kedua yang mungkin ditambahkan untuk memudahkan penomoran kolom
			if($data->val($i,1) == 'KOLOM' or empty($data->val($i,1))) {
				continue;
			}
			$ada_baris = true;
			$baris_pertama = $i;
			break;
		}
		if ($ada_baris) return $baris_pertama;
		else return 0;
	}

	private function tulis_rtm($baris){
		$id_rtm = $baris[$this->kolom['id_rtm']];
		$rtm_level = $baris[$this->kolom['rtm_level']];
		if($rtm_level > 1)$rtm_level=2; //Hanya rekam kepala & anggota rumah tangga
		$nik = $baris[$this->kolom['nik']];

		$query = $this->db->where('nik',$nik)->get('tweb_penduduk');
		if ($query->num_rows() == 0){
			// Laporkan penduduk BDT tidak ada di database
			echo "<a>".$id_rtm." ".$rtm_level." ".$nik." ".$baris[$this->kolom['nama']]." == tidak ditemukan di database penduduk. </a><br>";
			return false;
		} else {
			$penduduk = array();
			$penduduk['id_rtm'] = $id_rtm;
			$penduduk['rtm_level'] = $rtm_level;
			$this->db->where('nik',$nik)->update('tweb_penduduk',$penduduk);

			if($rtm_level == 1) {
				$rtm['id'] = $id_rtm;
				$rtm['nik_kepala'] = $query->row()->id;
				$rtm['no_kk'] = $id_rtm;
	      // Tambah rtm atau update kalau sudah ada
	      $sql = $this->db->insert_string('tweb_rtm', $rtm);
	      $sql .= " ON DUPLICATE KEY UPDATE
	          nik_kepala = VALUES(nik_kepala),
	          no_kk = VALUES(no_kk)";
	      $this->db->query($sql);
			}
		}
		return true;
	}

}
?>