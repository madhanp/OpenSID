<?php class Bdt_Model extends CI_Model{

	function __construct(){
		parent::__construct();

		$this->kolom = array(
			'id_rtm' => 2,
			'nama' => 80,
			'nik' => 81,
			'rtm_level' => 82,
			'awal_respon_rt' => 14,
			'awal_respon_penduduk' => 82
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
		$this->$baris = $data->rowcount($sheet_index=0);
		$$this->baris_pertama = $this->cari_baris_pertama($data, $this->$baris);
		if ($$this->baris_pertama <= 0) {
			$_SESSION['error_msg'].= " -> Tidak ada data";
			$_SESSION['success']=-1;
			return;
		}

		if ($tipe_analisis == 'rumah_tangga'){
			$this->kolom_subjek = $this->kolom['id_rtm'];
			$this->kolom_indikator_pertama = $this->kolom['awal_respon_rt'];
		} else {
			$this->kolom_subjek = $this->kolom['nik'];
			$this->kolom_indikator_pertama = $this->kolom['awal_respon_penduduk'];
		}

		$gagal = 0;
		$this->abaikan = array();
		$data_sheet = $data->sheets[0]['cells'];
		for($i=$this->baris_pertama; $i<=$baris; $i++){
			if (!$this->tulis_rtm($data_sheet[$i])) {
				$this->abaikan[] = $data_sheet[$i][$this->kolom['nik']];
				$gagal++;
			}
			// kumpulkan semua subjek (NIK untuk penduduk atau id_rtm utk rumah tangga ...)
			$this->list_subjek[] = $data_sheet[$i][$this->kolom_subjek];
		}
		// ambil semua id_subjek (cari menggunakan NIK atau id_rtm  --> $list_id_subjek)
		// $list_id_subjek = array(nik => id atau id_rtm => id, ..)
		$this->hapus_respon($list_id_subjek);
		$this->impor_respon($data_sheet);


		echo "<br>JUMLAH GAGAL : $gagal</br>";
		echo "<a href='".site_url()."analisis_respon'>LANJUT</a>";
	}

	private function hapus_respon($list_id_subject){
		$per = $this->get_aktif_periode();
			$sql = "DELETE FROM analisis_respon WHERE id_subjek IN(?) AND id_periode=?";
			$this->db->query($sql,array($list_id_subject,$per));
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
			$rtm = $this->db->where('no_kk',$id_rtm)->get('tweb_rtm')->row()->id;
			if($rtm){
				// Update
				if($rtm_level == 1){
					$this->db->update('tweb_rtm',array('nik_kepala' => $query->row()->id));
				}
			} else {
				// Tambah
				$rtm_data = array();
				$rtm_data['no_kk'] = $id_rtm;
				if($rtm_level == 1) $rtm_data['nik_kepala'] = $query->row()->id;
	      $this->db->insert('tweb_rtm', $rtm_data);
	      $rtm = $this->db->insert_id();
			}
		}
		$penduduk = array();
		$penduduk['id_rtm'] = $rtm;
		$penduduk['rtm_level'] = $rtm_level;
		$this->db->where('nik',$nik)->update('tweb_penduduk',$penduduk);
		return true;
	}

	private function impor_respon($data_sheet){
		$sql = "SELECT * FROM analisis_indikator WHERE id_master=? ORDER BY id ASC";
		$query = $this->db->query($sql,$_SESSION['analisis_master']);
		$indikator = $query->result_array();
		$n = 0;
		$respon = array();
		$kolom = $this->kolom_indikator_pertama;
		for($i=$this->baris_pertama; $i<=$this->baris; $i++){
			// Jangan impor jika NIK tidak ada di database
			if($data_sheet[$i][$this->kolom['nik']] in $this_abaikan){
				continue;
			}

			// cari id_subjek
			// id_subjek = $this->list_id_subjek[$data_sheet[$i][$this->kolom['nik']] atau 'id_rtm']
			foreach($indikator as $key => $indi){
				$isi = $data_sheet[$i][$kolom + $key];
				switch ($indi['id_tipe']) {
					case 1:
						$list_parameter = $this->respon_pilihan_tunggal($indi['id'],$isi);
						break;
					case 2:
						$list_parameter = $this->respon_pilihan_ganda($indi['id'],$isi);
						break;

					default:
						$list_parameter = $this->respon_isian($indi['id'],$isi);
						break;
				}
				// Himpun respon untuk semua indikator untuk semua baris
				foreach($list_paramater as $parameter){
					$respon[$n]['id_indikator']	= $indi['id'];
					$respon[$n]['id_subjek']		= $this->list_id_subjek[$data_sheet[$i][$this->kolom_subjek]];
					$respon[$n]['id_periode']		= $per;
					$respon[$n]['parameter'] = $parameter;
					$n++;
				}
			}
		}
		if($n>0)
			$outp = $this->db->insert_batch('analisis_respon',$respon);
		else
			$outp = false;
		$this->pre_update();

		if($outp) $_SESSION['success']=1;
			else $_SESSION['success']=-1;
	}

	private function respon_pilihan_tunggal($id_indikator, $isi){
		$param = $this->db->select('id')
				->where('id_indikator',$id_indikator)
				->where('kode_jawaban',$isi)
				->get('analisis_parameter')
				->row_array();
		if($param){
			$in_param = $param['id'];
		}else{
			if($isi == "")
				$in_param = 0;
			else
				$in_param = -1;
		}
		return array($in_param);
	}

	private function respon_pilihan_ganda($id_indikator, $isi){
		$id_isi = explode(",",$isi);
		$in_param = array();
		foreach ($id_isi as $isi){
			$param = $this->db->select('id')
					->where('id_indikator',$id_indikator)
					->where('kode_jawaban',$isi)
					->get('analisis_parameter')
					->row_array();
			if($param['id'] != ""){
				$in_param[] = $param['id'];
			}
		}
		return $in_param;
	}

	private function respon_isian($id_indikator, $isi){
		$param = $this->db->select('id')
				->where('id_indikator',$id_indikator)
				->where('kode_jawaban',$isi)
				->get('analisis_parameter')
				->row_array();

		// apakah sdh ada jawaban yg sama
		if($param){
			$in_param = $param['id'];
		}else{
			$paramater = array();
			$parameter['jawaban']	= $isi;
			$parameter['id_indikator']	= $id_indikator;
			$parameter['asign']			= 0;
			$this->db->insert('analisis_parameter',$parameter);
			$in_param = $this->db->insert_id();
		}
		return array($in_param);
	}
}
?>