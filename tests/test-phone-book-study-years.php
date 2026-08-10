<?php
define( 'ABSPATH', __DIR__ . '/' );

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

class Olama_Core_Repository {
	public function table( $name ) {
		return $name;
	}
}

class Olama_Core_Test_Phone_Book_Years_DB {
	public function get_col( $query ) {
		return array( '2026-2027', '2027/2026', '2025/2026', '2025-2026' );
	}
}

class Olama_Core_Test_Phone_Book_Years_Calendar {
	public function resolve_external_year( $source, $value ) {
		$ids = array(
			'2026-2027' => 1,
			'2025/2026' => 2,
			'2025-2026' => 2,
		);
		return isset( $ids[ $value ] ) ? (object) array( 'id' => $ids[ $value ] ) : null;
	}

	public function canonical_year_code( $id ) {
		return 1 === (int) $id ? '2026-2027' : '2025-2026';
	}

	public function external_year_code( $id, $source ) {
		return 1 === (int) $id ? '2026-2027' : '2025/2026';
	}
}

class Olama_Core_Test_Phone_Book_Years_Container {
	public function academic_calendar() {
		return new Olama_Core_Test_Phone_Book_Years_Calendar();
	}
}

function olama_core() {
	return new Olama_Core_Test_Phone_Book_Years_Container();
}

$wpdb = new Olama_Core_Test_Phone_Book_Years_DB();
require_once dirname( __DIR__ ) . '/includes/class-olama-core-audience-service.php';

$service = new Olama_Core_Audience_Service( new Olama_Core_Repository() );
$actual  = $service->get_phone_book_study_years();
$expected = array( '2026-2027', '2025/2026' );

if ( $expected !== $actual ) {
	fwrite( STDERR, 'Phone Book Oracle years: FAIL ' . json_encode( $actual ) . PHP_EOL );
	exit( 1 );
}

echo "Phone Book Oracle years: PASS\n";
