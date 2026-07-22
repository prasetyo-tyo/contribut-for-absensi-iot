<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
?>
    <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

      <!-- Sidebar - Brand -->
      <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
          <br><center><img src="../src/img/1.png" alt="Logo" style="width: 60px; height: auto;"></center><br>
        <div class="sidebar-brand-text mx-3">PT. SUGIH BOGA NUSANTARA</div>
      </a>
      <!-- Divider -->
      <hr class="sidebar-divider my-0">

      <!-- Nav Item - Dashboard -->
      <li class="nav-item active">
        <a class="nav-link" href="index.php">
          <i class="fas fa-fw fa-tachometer-alt"></i>
          <span>Dashboard</span></a>
      </li>

      <!-- Divider -->
      <hr class="sidebar-divider">

      <!-- Heading -->
      <div class="sidebar-heading">
        Data Absensi
      </div>
	  
	  <!-- Nav Item - Charts -->
      <li class="nav-item">
        <a class="nav-link" href="data_karyawan-index.php">
          <i class="fas fa-fw fa-user"></i>
          <span>Karyawan</span></a>
      </li>
	  
	  <!-- Nav Item - Charts -->
      <li class="nav-item">
        <a class="nav-link" href="data_absen-index.php">
          <i class="fas fa-fw fa-table"></i>
          <span>Absensi</span></a>
      </li>

	  <li class="nav-item">
        <a class="nav-link" href="data_outlet-index.php">
          <i class="fas fa-fw fa-store"></i>
          <span>Outlet</span></a>
      </li>

	  <li class="nav-item">
        <a class="nav-link" href="data_user-index.php">
          <i class="fas fa-fw fa-user-shield"></i>
          <span>User</span></a>
      </li>

	  <li class="nav-item">
        <a class="nav-link" href="pengaturan_keamanan.php">
          <i class="fas fa-fw fa-lock"></i>
          <span>Pengaturan</span></a>
      </li>
	  
	  <!-- Nav Item - Charts -->
      <li class="nav-item">
        <a class="nav-link" href="data_invalid-index.php">
          <i class="fas fa-fw fa-exclamation-triangle"></i>
          <span>Invalid</span></a>
      </li>
	

      <!-- Divider -->
      <hr class="sidebar-divider">

      <!-- Heading -->
      <div class="sidebar-heading">
        Laporan
      </div>

      <!-- Nav Item - Pages Collapse Menu -->
      <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
          <i class="fas fa-fw fa-folder"></i>
          <span>Rekap Absensi</span>
        </a>
        <div id="collapsePages" class="collapse" aria-labelledby="headingPages" data-parent="#accordionSidebar">
          <div class="bg-white py-2 collapse-inner rounded">
            <a class="collapse-item" href="rekap_data_absen-index.php">Harian</a>
            <a class="collapse-item" href="rekap_absen_bulanan-index.php">Bulanan</a>
          </div>
        </div>
      </li>

      <!-- Divider -->
      <hr class="sidebar-divider d-none d-md-block">

      <!-- Sidebar Toggler (Sidebar) -->
      <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
      </div>

    </ul>
