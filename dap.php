<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Logging function
if( !function_exists( 'dap' ) ){
	function dap( $x, $echo = false, $backtrace = false ){
		$return_1 = false;
		$return_2 = false; // Always wrap with <pre>
		
		$type = gettype( $x );
		switch( $type ){
			case 'boolean':
				$return_1 = $type . ': ' . ( $x ? 'TRUE' : 'FALSE' );
				break;
			case 'integer':
			case 'double':
			case 'float':
			case 'string':
				$return_1 = $type . ': ' . $x;
				break;
			case 'NULL':
				$return_1 = $type;
				break;
			case 'resource':
			case 'unknown type':
				$return_1 = $type;
			case 'object':
			case 'array':
			default:
				$return_2 = $x;
				break;
		}

		$debug = debug_backtrace();
		$bt = [];
		if( !$backtrace ){
			// Only return the last trace
			$caller = array_shift($debug);
			$bt[] = $caller['file'] . ': ' . $caller['line'];
			if( isset( $caller['object'], $caller['function'] ) ){
				$bt[] = $caller['object'] . '\\' . $caller['function'];
			}
		} else {
			$bt[] = $debug;
		}
		
		$start = '*** DAP LOG START ***';
			
		error_log( $start );
		foreach( $bt as $line ){
			if( !is_string( $line ) ){
				error_log( print_r( $line, true) );
			} else {
				error_log( $line );
			}
		}
		
		if( $return_1 ){
			if( !is_string( $return_1 ) ){
				error_log( print_r( $return_1, true ) );
			} else {
				error_log( $return_1 );		
			}
		}
		if( $return_2 ){
			if( !is_string( $return_2 ) ){
				error_log( print_r( $return_2, true ) );
			} else {
				error_log( $return_2 );
			}
		}
		
		error_log( '---------------------' );
		
		if( $echo ){
			echo '<div style="border: 1px red solid; padding: 10px; background-color: black; color: lightgray;">';
			echo '<h2 style="color:white;">' . $start . '</h2>';
			echo '<p>';
			foreach( $bt as $line ){
				if( !is_string( $line ) ){
					echo '<pre>' . print_r( $line, true ) . '</pre>';
				} else {
					echo $line;
				}
			}
			echo '</p><hr>';
			if( $return_1 ){
				echo '<p>';
				if( !is_string( $return_1 ) ){
					echo '<pre>' . print_r( $return_1, true ) . '</pre>';
				} else {
					echo ($return_1 );
				}
				echo '</p>';
			}
			if( $return_2 ){
				echo '<p>';
				if( !is_string( $return_2 ) ){
					echo '<pre>' . print_r( $return_2, true ) . '</pre>';
				} else {
					echo ($return_2 );
				}
				echo '</p>';
			}
			echo '</div><!-- DAP LOG END -->';
		}


		/*
		if(is_object($x) || is_array($x)){
			$return = print_r($x,true);
			$pre = '<pre>';
			$post = '</pre>';
		} else {
			$return = $x;
			$pre = '';
			$post = '';
		}

		error_log($return);
		if($echo) echo $pre.$return.$post;
		*/
	}
}