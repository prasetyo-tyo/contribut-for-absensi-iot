<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Jika belum login, arahkan ke halaman login
    header("location: login.php");
    exit;
}

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Sisa kode data_absen-index.php Anda ...
?>
<!DOCTYPE html>
<html lang="en">

<head>

  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="">
  <meta name="author" content="">

  <title>PT. SUGIH BOGA NUSANTARA - Dashboard</title>

  <!-- Custom fonts for this template-->
  <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
  <link rel="icon" href="../src/img/1.png" type="image/x-icon">
  <!-- Custom styles for this template-->
  <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">

  <!-- Custom styles for this page -->
  <link href="../src/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
  
  
  <!-- Bootstrap core JavaScript-->
  <script src="../src/vendor/jquery/jquery.min.js"></script>
  <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script type="text/javascript">
        $(document).ready(function(){
            $('[data-toggle="tooltip"]').tooltip();
        });
   </script>

</head>

<body id="page-top">

  <!-- Page Wrapper -->
  <div id="wrapper">

    <!-- Sidebar -->
	<?php include 'partial_sidebar.php';?>
	<!-- End of Sidebar -->

    <!-- Content Wrapper -->
    <div id="content-wrapper" class="d-flex flex-column">

      <!-- Main Content -->
      <div id="content">

        <!-- Topbar -->
		<?php include 'partial_topbar.php';?>
		<!-- End of Topbar -->

        <!-- Begin Page Content -->
        <div class="container-fluid">

          <!-- Page Heading -->
          <h1 class="h3 mb-2 text-gray-800">Rekap Absensi</h1>
          
          <!-- DataTales Example -->
          <div class="card shadow mb-4">
            <div class="card-header py-3">
              <h6 class="m-0 font-weight-bold text-primary">Rekap Absensi Bulanan</h6>
            </div>
            <div class="card-body">
					<?php include 'rekap_bulanan-export-controls.php'; ?>
                    <div class="col-md-12">
						<div class="row">
							<div class="col-md-6">
								<form action="rekap_absen_bulanan-index.php" method="get">
								<div class="col">
								  <input type="text" class="form-control" placeholder="Pencarian data karyawan" name="search">
								</div>
								</form>
							</div>	
						</div>
						<br>

						<?php
						require_once "config.php";

						//Pagination
						if (isset($_GET['pageno']) && ctype_digit((string) $_GET['pageno']) && (int) $_GET['pageno'] > 0) {
							$pageno = (int) $_GET['pageno'];
						} else {
							$pageno = 1;
						}
						$no_of_records_per_page = 10;
						$offset = ($pageno-1) * $no_of_records_per_page;
						$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

						$total_pages_sql = "SELECT COUNT(*) AS count FROM data_karyawan WHERE status_karyawan = 'AKTIF'";
						$total_rows = 0;
						if ($search !== '') {
							$total_pages_sql .= " AND CONCAT(uid, nama, division, COALESCE(jabatan, ''), mail, alamat) LIKE ?";
						}
						if ($countStmt = mysqli_prepare($link, $total_pages_sql)) {
							if ($search !== '') {
								$searchParam = '%' . $search . '%';
								mysqli_stmt_bind_param($countStmt, "s", $searchParam);
							}
							mysqli_stmt_execute($countStmt);
							$countResult = mysqli_stmt_get_result($countStmt);
							if ($countResult && ($countRow = mysqli_fetch_assoc($countResult))) {
								$total_rows = (int) $countRow['count'];
							}
							mysqli_stmt_close($countStmt);
						}
						$total_pages = ceil($total_rows / $no_of_records_per_page);
						
						//Column sorting on column name
						$orderBy = array('uid', 'nama', 'division', 'jabatan', 'mail', 'alamat'); 
						$order = 'id';
						if (isset($_GET['order']) && in_array($_GET['order'], $orderBy)) {
								$order = $_GET['order'];
							}

						//Column sort order
						$sortBy = array('asc', 'desc'); $sort = 'desc';
						if (isset($_GET['sort']) && in_array($_GET['sort'], $sortBy)) {                                                                    
							  if($_GET['sort']=='asc') {                                                                                                                            
								$sort='desc';
								}                                                                                   
						else {
							$sort='asc';
							}                                                                                                                           
						}
						// Attempt select query execution
						$sql = "SELECT * FROM data_karyawan WHERE status_karyawan = 'AKTIF'";
						if ($search !== '') {
							$sql .= " AND CONCAT(uid, nama, division, COALESCE(jabatan, ''), mail, alamat) LIKE ?";
						}
						$sql .= " ORDER BY $order $sort LIMIT ?, ?";

						if($stmt = mysqli_prepare($link, $sql)){
							if ($search !== '') {
								$searchParam = '%' . $search . '%';
								mysqli_stmt_bind_param($stmt, "sii", $searchParam, $offset, $no_of_records_per_page);
							} else {
								mysqli_stmt_bind_param($stmt, "ii", $offset, $no_of_records_per_page);
							}
							mysqli_stmt_execute($stmt);
							$result = mysqli_stmt_get_result($stmt);
							if(mysqli_num_rows($result) > 0){
								echo "<div class='table-responsive'>";
								echo "<div style='overflow-x: auto; width: 100%;'>";
								echo "<table class='table table-bordered table-striped'>";
									echo "<thead>";
										echo "<tr>";
											echo "<th><a href='?search=" . urlencode($search) . "&order=nama&sort=$sort'>Nama</a></th>";
											echo "<th><a href='?search=" . urlencode($search) . "&order=division&sort=$sort'>Divisi</a></th>";
                            echo "<th><a href='?search=" . urlencode($search) . "&order=jabatan&sort=$sort'>Jabatan</a></th>";
											echo "<th><a href='?search=" . urlencode($search) . "&order=mail&sort=$sort'>Email</a></th>";
											echo "<th><a href='?search=" . urlencode($search) . "&order=alamat&sort=$sort'>Alamat</a></th>";
											
											echo "<th>Action</th>";
										echo "</tr>";
									echo "</thead>";
									echo "<tbody>";
									while($row = mysqli_fetch_array($result)){
										echo "<tr>";
										echo "<td>" . escape_html($row['nama']) . "</td>";echo "<td>" . escape_html($row['division']) . "</td>";echo "<td>" . escape_html($row['jabatan'] ?? '-') . "</td>";echo "<td>" . escape_html($row['mail']) . "</td>";echo "<td>" . escape_html($row['alamat']) . "</td>";
											echo "<td>";
												echo "<center><a href='rekap_bulanan-view.php?id=". urlencode((string) $row['id']) ."' title='Lihat' data-toggle='tooltip'><span class='fa fa-eye'></span></a></center>";
											echo "</td>";
										echo "</tr>";
									}
									echo "</tbody>";
								echo "</table>";
								echo "</div>";
								echo "</div>";
								?> 
								<div class="mt-3" style="width: 100%; overflow-x: visible;">
									<nav aria-label="Page navigation example" class="overflow-auto">
										<ul class="pagination justify-content-center flex-wrap">
											<li class="page-item"><a class="page-link" href="?pageno=1">First</a></li>
											<li class="page-item <?php if($pageno <= 1){ echo 'disabled'; } ?>">
												<a class="page-link" href="<?php if($pageno <= 1){ echo '#'; } else { echo "?pageno=".($pageno - 1); } ?>">Prev</a>
											</li>
											<li class="page-item <?php if($pageno >= $total_pages){ echo 'disabled'; } ?>">
												<a class="page-link" href="<?php if($pageno >= $total_pages){ echo '#'; } else { echo "?pageno=".($pageno + 1); } ?>">Next</a>
											</li>
											<li class="page-item"><a class="page-link" href="?pageno=<?php echo $total_pages; ?>">Last</a></li>
										</ul>
									</nav>
								</div>
	<?php
								// Free result set
								mysqli_free_result($result);
							} else{
								echo "<p class='lead'><em>No records were found.</em></p>";
							}
							mysqli_stmt_close($stmt);
						} else{
							echo "ERROR: Could not prepare query. " . mysqli_error($link);
						}

						// Close connection
						mysqli_close($link);
						?>
					</div>
				
			</div>
          </div>
		  
        </div>
        <!-- /.container-fluid -->

      </div>
      <!-- End of Main Content -->

      <!-- Footer -->
      <footer class="sticky-footer bg-white">
        <div class="container my-auto">
          <div class="copyright text-center my-auto">
            <span>Copyright &copy; Team 7</span>
          </div>
        </div>
      </footer>
      <!-- End of Footer -->

    </div>
    <!-- End of Content Wrapper -->

  </div>
  <!-- End of Page Wrapper -->

  <!-- Scroll to Top Button-->
  <a class="scroll-to-top rounded" href="#page-top">
    <i class="fas fa-angle-up"></i>
  </a>

  <!-- Logout Modal-->
  <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
          <button class="close" type="button" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">×</span>
          </button>
        </div>
        <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
          <a class="btn btn-primary" href="logout.php">Logout</a>
        </div>
      </div>
    </div>
  </div>


  <!-- Page level plugins -->
  <script src="../src/vendor/datatables/jquery.dataTables.min.js"></script>
  <script src="../src/vendor/datatables/dataTables.bootstrap4.min.js"></script>

  <!-- Custom scripts for all pages-->
  <script src="../src/js/sb-admin-2.min.js"></script>



</body>

</html>
