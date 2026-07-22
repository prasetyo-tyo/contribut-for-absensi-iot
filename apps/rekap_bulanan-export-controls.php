<?php
require_once 'config.php';
require_once 'karyawan_options.php';
require_once 'rekap_bulanan-export-helper.php';

$exportMonth = rekap_parse_month($_GET['set_bulan'] ?? '', $link);
$exportDivision = trim((string) ($_GET['division'] ?? ''));
$exportJabatan = trim((string) ($_GET['jabatan'] ?? ''));
$exportQuery = http_build_query([
    'set_bulan' => $exportMonth['value'],
    'division' => $exportDivision,
    'jabatan' => $exportJabatan,
]);
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.2.0/css/datepicker.min.css" rel="stylesheet">
<div class="card border-left-primary mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Filter Export Rekap Bulanan</h6>
        <a class="btn btn-sm btn-outline-primary collapsed" data-toggle="collapse" href="#filterExportRekap" role="button" aria-expanded="false" aria-controls="filterExportRekap">
            <i class="fas fa-chevron-up"></i>
        </a>
    </div>
    <div class="collapse" id="filterExportRekap">
        <div class="card-body">
            <form action="rekap_absen_bulanan-index.php" method="get">
                <div class="form-row">
                    <div class="form-group col-lg-3 col-md-6">
                        <label>Bulan</label>
                        <div class="input-group">
                            <input type="text"
                                   class="form-control"
                                   name="set_bulan"
                                   id="exportMonthPicker"
                                   value="<?php echo escape_html($exportMonth['value']); ?>"
                                   readonly
                                   required>
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-outline-secondary">Set</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group col-lg-3 col-md-6">
                        <label>Divisi</label>
                        <select class="form-control" name="division">
                            <option value="">Semua Divisi</option>
                            <?php render_options(get_division_options(), $exportDivision); ?>
                        </select>
                    </div>
                    <div class="form-group col-lg-3 col-md-6">
                        <label>Jabatan</label>
                        <select class="form-control" name="jabatan">
                            <option value="">Semua Jabatan</option>
                            <?php render_options(get_jabatan_options(), $exportJabatan); ?>
                        </select>
                    </div>
                    <div class="form-group col-lg-3 col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-filter"></i> Terapkan Filter
                        </button>
                    </div>
                </div>
            </form>

            <div class="mt-2">
                <a class="btn btn-outline-danger mb-1"
                   href="rekap_bulanan-export-zip.php?<?php echo escape_html($exportQuery); ?>&amp;format=pdf">
                    <i class="fas fa-file-archive"></i> ZIP PDF per Karyawan
                </a>
                <a class="btn btn-outline-success mb-1"
                   href="rekap_bulanan-export-zip.php?<?php echo escape_html($exportQuery); ?>&amp;format=xlsx">
                    <i class="fas fa-file-archive"></i> ZIP XLSX per Karyawan
                </a>
            </div>
            <small class="form-text text-muted">
                Periode data: <?php echo escape_html(attendance_period_label($exportMonth)); ?>.
                Kosongkan Divisi dan Jabatan untuk mengekspor seluruh karyawan.
                Setiap karyawan dibuat sebagai file terpisah di dalam ZIP.
            </small>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.2.0/js/bootstrap-datepicker.min.js"></script>
<script>
$(document).ready(function(){
    $("#exportMonthPicker").datepicker({
        format: "mm-yyyy",
        startView: "months",
        minViewMode: "months",
        autoclose: true
    });
});
</script>
