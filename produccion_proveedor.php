<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - GESTIÓN DE PRODUCCIÓN (POSTURA DIARIA) DEL PROVEEDOR
 * --------------------------------------------------------------------------------
 */

session_start();
require_once "forms/conexion.php";

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

// 2. OBTENER PROVEEDOR_ID
$stmtProv = $conn->prepare("SELECT id, nombre_empresa FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
if ($resProv->num_rows === 0) {
    die("Error: Su usuario no está vinculado a ningún proveedor.");
}
$provRow = $resProv->fetch_assoc();
$proveedor_id = (int)$provRow["id"];
$nombre_empresa = $provRow["nombre_empresa"];
$stmtProv->close();

$mensaje_exito = "";
$mensaje_error = "";

// 3. PROCESAR ACCIONES DE FORMULARIO (POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "agregar") {
        $granja_id = (int)($_POST["granja_id"] ?? 0);
        $fecha_produccion = trim($_POST["fecha_produccion"] ?? "");
        $huevos_recolectados = (int)($_POST["huevos_recolectados"] ?? 0);
        $medianos = max(0, (int)($_POST["medianos"] ?? 0));
        $jumbo = max(0, (int)($_POST["jumbo"] ?? 0));
        $no_viable = max(0, (int)($_POST["no_viable"] ?? 0));
        $merma = max(0, (int)($_POST["merma"] ?? 0));
        $observaciones = trim($_POST["observaciones"] ?? "");

        if ($granja_id <= 0 || empty($fecha_produccion) || $huevos_recolectados <= 0) {
            $mensaje_error = "Todos los campos obligatorios deben completarse correctamente.";
        } else {
            // Validar que la suma coincida
            $total_calculado = $medianos + $jumbo + $no_viable + $merma;
            if ($total_calculado !== $huevos_recolectados) {
                $mensaje_error = "La suma de huevos Medianos, Jumbo, No viables y Mermas debe coincidir exactamente con el total de huevos recolectados ($huevos_recolectados).";
            } else {
                // Validar propiedad de la granja y stock de cartones
                $stmtG = $conn->prepare("SELECT nombre, identificacion, stock_cartones FROM granjas WHERE id = ? AND proveedor_id = ?");
                $stmtG->bind_param("ii", $granja_id, $proveedor_id);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                if ($resG->num_rows === 0) {
                    $mensaje_error = "La granja seleccionada no es válida.";
                } else {
                    $granja_data = $resG->fetch_assoc();
                    $granja_nombre = $granja_data["nombre"];
                    $granja_identificacion = trim($granja_data["identificacion"]);
                    $stock_cartones = (int)$granja_data["stock_cartones"];

                    // Calcular cartones (1 cartón = 30 huevos viables)
                    $cartones_totales_necesarios = (int)ceil(($medianos + $jumbo) / 30);

                    if ($stock_cartones < $cartones_totales_necesarios) {
                        $mensaje_error = "Insumos insuficientes: La granja '$granja_nombre' requiere $cartones_totales_necesarios cartones de empaque, pero solo cuenta con $stock_cartones disponibles.";
                    } else {
                        // Generar código de lote unificado: GRJ{clave}-{fecha}-{consecutivo}
                        $fecha_lote = date('Ymd', strtotime($fecha_produccion));
                        $prefijo_lote = $granja_identificacion . "-" . $fecha_lote . "-";
                        
                        // Contar cuántos lotes únicos existen con este prefijo
                        $like_pattern = $prefijo_lote . "%";
                        $stmtCount = $conn->prepare("SELECT COUNT(DISTINCT codigo_lote) FROM produccion WHERE codigo_lote LIKE ?");
                        $stmtCount->bind_param("s", $like_pattern);
                        $stmtCount->execute();
                        $resCount = $stmtCount->get_result();
                        $countLotes = (int)($resCount->fetch_row()[0] ?? 0);
                        $stmtCount->close();

                        $consecutivo = str_pad($countLotes + 1, 3, "0", STR_PAD_LEFT);
                        $codigo_lote = $prefijo_lote . $consecutivo;

                        // Iniciar transacción
                        $conn->begin_transaction();
                        try {
                            // Descontar cartones de la granja
                            $nuevo_stock = $stock_cartones - $cartones_totales_necesarios;
                            $stmtUpG = $conn->prepare("UPDATE granjas SET stock_cartones = ? WHERE id = ?");
                            $stmtUpG->bind_param("ii", $nuevo_stock, $granja_id);
                            $stmtUpG->execute();
                            $stmtUpG->close();

                            // Insertar registro para Mediano (ID 3)
                            // Siempre creamos al menos la fila de Medianos para almacenar observaciones/no_viables/mermas
                            $prod_id = 3; // Mediano
                            $cant = $medianos;
                            $nv = $no_viable;
                            $mr = $merma;
                            $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_produccion)));

                            // Insertar en produccion
                            $stmtInsP = $conn->prepare("INSERT INTO produccion (proveedor_id, producto_id, granja_id, cantidad, no_viable, merma, fecha_produccion, observaciones, codigo_lote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmtInsP->bind_param("iiiiiisss", $proveedor_id, $prod_id, $granja_id, $cant, $nv, $mr, $fecha_produccion, $observaciones, $codigo_lote);
                            $stmtInsP->execute();
                            $stmtInsP->close();

                            // Insertar en inventario_huevos
                            $stmtInsI = $conn->prepare("INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad_inicial, cantidad, no_viable, merma, fecha_postura, fecha_caducidad, estado, granja_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
                            $stmtInsI->bind_param("iisiiiissi", $proveedor_id, $prod_id, $codigo_lote, $cant, $cant, $nv, $mr, $fecha_produccion, $fecha_caducidad, $granja_id);
                            $stmtInsI->execute();
                            $stmtInsI->close();

                            // Insertar registro para Jumbo (ID 1) si $jumbo > 0
                            if ($jumbo > 0) {
                                $prod_id = 1; // Jumbo
                                $cant = $jumbo;
                                $nv = 0;
                                $mr = 0;
                                $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_produccion)));

                                // Insertar en produccion
                                $stmtInsP = $conn->prepare("INSERT INTO produccion (proveedor_id, producto_id, granja_id, cantidad, no_viable, merma, fecha_produccion, observaciones, codigo_lote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmtInsP->bind_param("iiiiiisss", $proveedor_id, $prod_id, $granja_id, $cant, $nv, $mr, $fecha_produccion, $observaciones, $codigo_lote);
                                $stmtInsP->execute();
                                $stmtInsP->close();

                                // Insertar en inventario_huevos
                                $stmtInsI = $conn->prepare("INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad_inicial, cantidad, no_viable, merma, fecha_postura, fecha_caducidad, estado, granja_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
                                $stmtInsI->bind_param("iisiiiissi", $proveedor_id, $prod_id, $codigo_lote, $cant, $cant, $nv, $mr, $fecha_produccion, $fecha_caducidad, $granja_id);
                                $stmtInsI->execute();
                                $stmtInsI->close();
                            }

                            // Registrar auditoría
                            registrar_bitacora("Producción registrada", "Inventario", "El proveedor registró el lote unificado '$codigo_lote' en la granja '$granja_nombre' (Medianos: $medianos viables, Jumbo: $jumbo viables, No viables: $no_viable, Mermas: $merma).");

                            $conn->commit();
                            $mensaje_exito = "¡Producción y Lote Diario (" . $codigo_lote . ") registrados correctamente!";
                        } catch (Exception $e) {
                            $conn->rollback();
                            $mensaje_error = "Error en base de datos: " . $e->getMessage();
                        }
                    }
                }
                $stmtG->close();
            }
        }
    } elseif ($accion === "editar") {
        $codigo_lote = trim($_POST["codigo_lote"] ?? "");
        $granja_id = (int)($_POST["granja_id"] ?? 0);
        $fecha_produccion = trim($_POST["fecha_produccion"] ?? "");
        $huevos_recolectados = (int)($_POST["huevos_recolectados"] ?? 0);
        $medianos = max(0, (int)($_POST["medianos"] ?? 0));
        $jumbo = max(0, (int)($_POST["jumbo"] ?? 0));
        $no_viable = max(0, (int)($_POST["no_viable"] ?? 0));
        $merma = max(0, (int)($_POST["merma"] ?? 0));
        $observaciones = trim($_POST["observaciones"] ?? "");
        
        $motivo_modificacion = trim($_POST["motivo_modificacion"] ?? "");
        $observacion_detalle = trim($_POST["observacion_detalle"] ?? "");

        if (empty($codigo_lote) || $granja_id <= 0 || empty($fecha_produccion) || $huevos_recolectados <= 0 || empty($motivo_modificacion) || empty($observacion_detalle)) {
            $mensaje_error = "Todos los campos de edición son obligatorios, incluyendo el motivo y justificación de modificación.";
        } else {
            // Validar que la suma coincida
            $total_calculado = $medianos + $jumbo + $no_viable + $merma;
            if ($total_calculado !== $huevos_recolectados) {
                $mensaje_error = "La suma de huevos Medianos, Jumbo, No viables y Mermas debe coincidir exactamente con el total de huevos recolectados ($huevos_recolectados).";
            } else {
                // Verificar existencia y propiedad de la producción de este lote
                $stmtCheck = $conn->prepare("SELECT p.* FROM produccion p WHERE p.codigo_lote = ? AND p.proveedor_id = ?");
                $stmtCheck->bind_param("si", $codigo_lote, $proveedor_id);
                $stmtCheck->execute();
                $resCheck = $stmtCheck->get_result();
                
                if ($resCheck->num_rows === 0) {
                    $mensaje_error = "Registro de producción no encontrado para el lote '$codigo_lote'.";
                } else {
                    // Recopilar datos antiguos del lote
                    $old_medianos = 0;
                    $old_jumbo = 0;
                    $old_no_viable = 0;
                    $old_merma = 0;
                    $old_granja_id = 0;
                    
                    while ($old_row = $resCheck->fetch_assoc()) {
                        $old_granja_id = (int)$old_row["granja_id"];
                        if ((int)$old_row["producto_id"] === 3) {
                            $old_medianos = (int)$old_row["cantidad"];
                        } elseif ((int)$old_row["producto_id"] === 1) {
                            $old_jumbo = (int)$old_row["cantidad"];
                        }
                        // Sumamos no_viable y merma (se almacenan en las filas)
                        $old_no_viable += (int)$old_row["no_viable"];
                        $old_merma += (int)$old_row["merma"];
                    }

                    // Verificar si el lote ya tiene solicitudes de entrega
                    $stmtDeliv = $conn->prepare("SELECT COUNT(*) FROM detalle_entrega_cedis d INNER JOIN inventario_huevos i ON d.lote_id = i.id WHERE i.codigo_lote = ?");
                    $stmtDeliv->bind_param("s", $codigo_lote);
                    $stmtDeliv->execute();
                    $countDeliv = (int)($stmtDeliv->get_result()->fetch_row()[0] ?? 0);
                    $stmtDeliv->close();

                    if ($countDeliv > 0) {
                        $mensaje_error = "No se puede editar esta producción porque el lote '$codigo_lote' ya se encuentra en proceso de entrega al CEDIS.";
                    } else {
                        // Validar la granja de destino
                        $stmtG = $conn->prepare("SELECT nombre, stock_cartones FROM granjas WHERE id = ? AND proveedor_id = ?");
                        $stmtG->bind_param("ii", $granja_id, $proveedor_id);
                        $stmtG->execute();
                        $resG = $stmtG->get_result();
                        if ($resG->num_rows === 0) {
                            $mensaje_error = "La granja seleccionada no es válida.";
                        } else {
                            $granja_data = $resG->fetch_assoc();
                            $granja_nombre = $granja_data["nombre"];
                            $stock_cartones = (int)$granja_data["stock_cartones"];

                            // Calcular cartones
                            $old_cartones = (int)ceil(($old_medianos + $old_jumbo) / 30);
                            $new_cartones = (int)ceil(($medianos + $jumbo) / 30);
                            $diferencia_cartones = $new_cartones - $old_cartones;

                            // Validar insumos de cartones
                            $insumos_validos = true;
                            if ($old_granja_id !== $granja_id) {
                                if ($stock_cartones < $new_cartones) {
                                    $mensaje_error = "Insumos insuficientes en la granja '$granja_nombre'. Se requieren $new_cartones cartones.";
                                    $insumos_validos = false;
                                }
                            } else {
                                if ($diferencia_cartones > 0 && $stock_cartones < $diferencia_cartones) {
                                    $mensaje_error = "Insumos insuficientes en la granja '$granja_nombre'. Faltan $diferencia_cartones cartones adicionales.";
                                    $insumos_validos = false;
                                }
                            }

                            if ($insumos_validos) {
                                $conn->begin_transaction();
                                try {
                                    // Devolver y/o descontar cartones de granjas
                                    if ($old_granja_id !== $granja_id) {
                                        $conn->query("UPDATE granjas SET stock_cartones = stock_cartones + $old_cartones WHERE id = $old_granja_id");
                                        $conn->query("UPDATE granjas SET stock_cartones = stock_cartones - $new_cartones WHERE id = $granja_id");
                                    } else {
                                        $conn->query("UPDATE granjas SET stock_cartones = stock_cartones - $diferencia_cartones WHERE id = $granja_id");
                                    }

                                    $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_produccion)));

                                    // 1. Procesar Mediano (ID 3)
                                    $checkMed = $conn->query("SELECT id FROM produccion WHERE codigo_lote = '$codigo_lote' AND producto_id = 3");
                                    $hasMed = ($checkMed && $checkMed->num_rows > 0);
                                    
                                    if ($medianos > 0 || $jumbo == 0) {
                                        $cant = $medianos;
                                        $nv = $no_viable;
                                        $mr = $merma;
                                        
                                        if ($hasMed) {
                                            $stmtUpP = $conn->prepare("UPDATE produccion SET granja_id = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_produccion = ?, observaciones = ? WHERE codigo_lote = ? AND producto_id = 3");
                                            $stmtUpP->bind_param("iiiiiss", $granja_id, $cant, $nv, $mr, $fecha_produccion, $observaciones, $codigo_lote);
                                            $stmtUpP->execute();
                                            $stmtUpP->close();

                                            $stmtUpI = $conn->prepare("UPDATE inventario_huevos SET cantidad_inicial = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_postura = ?, fecha_caducidad = ?, granja_id = ? WHERE codigo_lote = ? AND producto_id = 3");
                                            $stmtUpI->bind_param("iiiiisss", $cant, $cant, $nv, $mr, $fecha_produccion, $fecha_caducidad, $granja_id, $codigo_lote);
                                            $stmtUpI->execute();
                                            $stmtUpI->close();
                                        } else {
                                            $prod_id = 3;
                                            $stmtInsP = $conn->prepare("INSERT INTO produccion (proveedor_id, producto_id, granja_id, cantidad, no_viable, merma, fecha_produccion, observaciones, codigo_lote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                            $stmtInsP->bind_param("iiiiiisss", $proveedor_id, $prod_id, $granja_id, $cant, $nv, $mr, $fecha_produccion, $observaciones, $codigo_lote);
                                            $stmtInsP->execute();
                                            $stmtInsP->close();

                                            $stmtInsI = $conn->prepare("INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad_inicial, cantidad, no_viable, merma, fecha_postura, fecha_caducidad, estado, granja_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
                                            $stmtInsI->bind_param("iisiiiissi", $proveedor_id, $prod_id, $codigo_lote, $cant, $cant, $nv, $mr, $fecha_produccion, $fecha_caducidad, $granja_id);
                                            $stmtInsI->execute();
                                            $stmtInsI->close();
                                        }
                                    } else {
                                        // Si ahora medianos es 0 y jumbo > 0, ponemos la fila de Mediano existente en 0 si es que existe
                                        if ($hasMed) {
                                            $conn->query("UPDATE produccion SET cantidad = 0, no_viable = 0, merma = 0, granja_id = $granja_id, fecha_produccion = '$fecha_produccion', observaciones = '$observaciones' WHERE codigo_lote = '$codigo_lote' AND producto_id = 3");
                                            $conn->query("UPDATE inventario_huevos SET cantidad_inicial = 0, cantidad = 0, no_viable = 0, merma = 0, granja_id = $granja_id, fecha_postura = '$fecha_produccion', fecha_caducidad = '$fecha_caducidad' WHERE codigo_lote = '$codigo_lote' AND producto_id = 3");
                                        }
                                    }

                                    // 2. Procesar Jumbo (ID 1)
                                    $checkJumbo = $conn->query("SELECT id FROM produccion WHERE codigo_lote = '$codigo_lote' AND producto_id = 1");
                                    $hasJumbo = ($checkJumbo && $checkJumbo->num_rows > 0);

                                    if ($jumbo > 0) {
                                        $cant = $jumbo;
                                        $nv = 0;
                                        $mr = 0;

                                        if ($hasJumbo) {
                                            $stmtUpP = $conn->prepare("UPDATE produccion SET granja_id = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_produccion = ?, observaciones = ? WHERE codigo_lote = ? AND producto_id = 1");
                                            $stmtUpP->bind_param("iiiiiss", $granja_id, $cant, $nv, $mr, $fecha_produccion, $observaciones, $codigo_lote);
                                            $stmtUpP->execute();
                                            $stmtUpP->close();

                                            $stmtUpI = $conn->prepare("UPDATE inventario_huevos SET cantidad_inicial = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_postura = ?, fecha_caducidad = ?, granja_id = ? WHERE codigo_lote = ? AND producto_id = 1");
                                            $stmtUpI->bind_param("iiiiisss", $cant, $cant, $nv, $mr, $fecha_produccion, $fecha_caducidad, $granja_id, $codigo_lote);
                                            $stmtUpI->execute();
                                            $stmtUpI->close();
                                        } else {
                                            $prod_id = 1;
                                            $stmtInsP = $conn->prepare("INSERT INTO produccion (proveedor_id, producto_id, granja_id, cantidad, no_viable, merma, fecha_produccion, observaciones, codigo_lote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                            $stmtInsP->bind_param("iiiiiisss", $proveedor_id, $prod_id, $granja_id, $cant, $nv, $mr, $fecha_produccion, $observaciones, $codigo_lote);
                                            $stmtInsP->execute();
                                            $stmtInsP->close();

                                            $stmtInsI = $conn->prepare("INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad_inicial, cantidad, no_viable, merma, fecha_postura, fecha_caducidad, estado, granja_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
                                            $stmtInsI->bind_param("iisiiiissi", $proveedor_id, $prod_id, $codigo_lote, $cant, $cant, $nv, $mr, $fecha_produccion, $fecha_caducidad, $granja_id);
                                            $stmtInsI->execute();
                                            $stmtInsI->close();
                                        }
                                    } else {
                                        // Si jumbo es 0 y antes era > 0, actualizamos a 0
                                        if ($hasJumbo) {
                                            $conn->query("UPDATE produccion SET cantidad = 0, no_viable = 0, merma = 0, granja_id = $granja_id, fecha_produccion = '$fecha_produccion', observaciones = '$observaciones' WHERE codigo_lote = '$codigo_lote' AND producto_id = 1");
                                            $conn->query("UPDATE inventario_huevos SET cantidad_inicial = 0, cantidad = 0, no_viable = 0, merma = 0, granja_id = $granja_id, fecha_postura = '$fecha_produccion', fecha_caducidad = '$fecha_caducidad' WHERE codigo_lote = '$codigo_lote' AND producto_id = 1");
                                        }
                                    }

                                    // Registrar en bitácora (Auditoría con justificación)
                                    $nCompleto = ($_SESSION["nombre"] ?? "Proveedor") . " (" . $nombre_empresa . ")";
                                    $desc_cambios = "Modificación de Lote unificado '$codigo_lote' en la granja '$granja_nombre'.\n" .
                                                    "Motivo: '$motivo_modificacion'.\n" .
                                                    "Justificación: '$observacion_detalle'.\n" .
                                                    "Cantidades anteriores -> Medianos: $old_medianos, Jumbo: $old_jumbo, No viables: $old_no_viable, Mermas: $old_merma.\n" .
                                                    "Nuevas cantidades -> Medianos: $medianos, Jumbo: $jumbo, No viables: $no_viable, Mermas: $merma.\n" .
                                                    "Realizado por: $nCompleto.";
                                                    
                                    registrar_bitacora("Lote editado", "Inventario", $desc_cambios);
                                    
                                    $conn->commit();
                                    $mensaje_exito = "¡El lote unificado '$codigo_lote' se actualizó correctamente con su registro de auditoría!";
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $mensaje_error = "Error al editar: " . $e->getMessage();
                                }
                            }
                        }
                        $stmtG->close();
                    }
                }
                $stmtCheck->close();
            }
        }
    } elseif ($accion === "eliminar") {
        $codigo_lote = trim($_POST["codigo_lote"] ?? "");

        if (empty($codigo_lote)) {
            $mensaje_error = "Código de lote inválido para eliminación.";
        } else {
            // Verificar existencia y propiedad
            $stmtCheck = $conn->prepare("SELECT granja_id, SUM(cantidad) AS total_viables FROM produccion WHERE codigo_lote = ? AND proveedor_id = ? GROUP BY granja_id");
            $stmtCheck->bind_param("si", $codigo_lote, $proveedor_id);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if ($resCheck->num_rows === 0) {
                $mensaje_error = "Registro de producción no encontrado para el lote '$codigo_lote'.";
            } else {
                $prod = $resCheck->fetch_assoc();
                $cantidad_viables = (int)$prod["total_viables"];
                $granja_id = (int)$prod["granja_id"];

                // Verificar si está asociada a entrega al CEDIS
                $stmtDeliv = $conn->prepare("SELECT COUNT(*) FROM detalle_entrega_cedis d INNER JOIN inventario_huevos i ON d.lote_id = i.id WHERE i.codigo_lote = ?");
                $stmtDeliv->bind_param("s", $codigo_lote);
                $stmtDeliv->execute();
                $countDeliv = (int)($stmtDeliv->get_result()->fetch_row()[0] ?? 0);
                $stmtDeliv->close();

                if ($countDeliv > 0) {
                    $mensaje_error = "No se puede eliminar este registro porque el lote '$codigo_lote' ya se encuentra en proceso de entrega al CEDIS.";
                } else {
                    $conn->begin_transaction();
                    try {
                        // Devolver cartones
                        $cartones_devueltos = (int)ceil($cantidad_viables / 30);
                        $conn->query("UPDATE granjas SET stock_cartones = stock_cartones + $cartones_devueltos WHERE id = $granja_id");

                        // Eliminar lote de inventario
                        $stmtDelI = $conn->prepare("DELETE FROM inventario_huevos WHERE codigo_lote = ? AND proveedor_id = ?");
                        $stmtDelI->bind_param("si", $codigo_lote, $proveedor_id);
                        $stmtDelI->execute();
                        $stmtDelI->close();

                        // Eliminar producción
                        $stmtDelP = $conn->prepare("DELETE FROM produccion WHERE codigo_lote = ? AND proveedor_id = ?");
                        $stmtDelP->bind_param("si", $codigo_lote, $proveedor_id);
                        $stmtDelP->execute();
                        $stmtDelP->close();

                        registrar_bitacora("Producción eliminada", "Inventario", "El proveedor eliminó permanentemente el lote diario '$codigo_lote', liberando $cartones_devueltos cartones.");
                        $conn->commit();
                        $mensaje_exito = "¡El lote diario '$codigo_lote' y sus registros asociados fueron eliminados con éxito!";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $mensaje_error = "Error al eliminar: " . $e->getMessage();
                    }
                }
            }
            $stmtCheck->close();
        }
    }
}

// 4. OBTENER INFORMACIÓN DE VISTA
// Lista de Granjas
$granjas = [];
$stmtG = $conn->prepare("SELECT id, nombre, identificacion, stock_cartones FROM granjas WHERE proveedor_id = ? ORDER BY nombre ASC");
$stmtG->bind_param("i", $proveedor_id);
$stmtG->execute();
$resG = $stmtG->get_result();
while ($row = $resG->fetch_assoc()) {
    $granjas[] = $row;
}
$stmtG->close();

// Lista de Productos
$productos = [];
$resP = $conn->query("SELECT id, nombre, tamano FROM productos WHERE activo = 1 ORDER BY nombre ASC");
while ($row = $resP->fetch_assoc()) {
    $productos[] = $row;
}
usort($productos, function($a, $b) {
    $getWeight = function($p) {
        $tamano = strtolower($p['tamano']);
        if (strpos($tamano, 'chico') !== false) return 1;
        if (strpos($tamano, 'mediano') !== false) return 2;
        return 3;
    };
    return $getWeight($a) - $getWeight($b);
});

// Historial de Producción
$historial = [];
$queryH = "SELECT p.codigo_lote, p.fecha_produccion, p.granja_id, g.nombre AS granja_nombre, g.identificacion AS granja_identificacion,
                  SUM(p.cantidad + p.no_viable + p.merma) AS huevos_recolectados,
                  SUM(CASE WHEN p.producto_id = 3 THEN p.cantidad ELSE 0 END) AS medianos,
                  SUM(CASE WHEN p.producto_id = 1 THEN p.cantidad ELSE 0 END) AS jumbo,
                  SUM(p.no_viable) AS no_viable,
                  SUM(p.merma) AS merma,
                  MAX(p.observaciones) AS observaciones,
                  (SELECT COUNT(*) FROM detalle_entrega_cedis d INNER JOIN inventario_huevos i ON d.lote_id = i.id WHERE i.codigo_lote = p.codigo_lote) AS en_entrega
           FROM produccion p
           INNER JOIN productos pr ON p.producto_id = pr.id
           LEFT JOIN granjas g ON p.granja_id = g.id
           WHERE p.proveedor_id = ?
           GROUP BY p.codigo_lote, p.fecha_produccion, p.granja_id, g.nombre, g.identificacion
           ORDER BY p.fecha_produccion DESC, p.codigo_lote DESC";
$stmtH = $conn->prepare($queryH);
$stmtH->bind_param("i", $proveedor_id);
$stmtH->execute();
$resH = $stmtH->get_result();
while ($row = $resH->fetch_assoc()) {
    $historial[] = $row;
}
$stmtH->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro de Producción - ECOALI</title>
  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/proveedor.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
</head>
<body>

<div class="provider-container">
  
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">🌱 ECOALI</div>

    <div class="profile-card">
      <div class="avatar">👨‍🌾</div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre_empresa); ?></h4>
        <p>Granjero Proveedor</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <a href="dashboard_proveedor.php" class="menu-link <?php echo ($current_page === 'dashboard_proveedor.php') ? 'active' : ''; ?>">
        <span>🚜</span> <span>Mi Resumen</span>
      </a>
      <a href="produccion_proveedor.php" class="menu-link <?php echo ($current_page === 'produccion_proveedor.php') ? 'active' : ''; ?>">
        <span>🥚</span> <span>Registrar Postura (Recolección)</span>
      </a>
      <a href="lotes_proveedor.php" class="menu-link <?php echo ($current_page === 'lotes_proveedor.php') ? 'active' : ''; ?>">
        <span>📦</span> <span>Mis Lotes de Huevos</span>
      </a>
      <a href="inventario_proveedor.php" class="menu-link <?php echo ($current_page === 'inventario_proveedor.php') ? 'active' : ''; ?>">
        <span>🧺</span> <span>Mi Almacén (Stock)</span>
      </a>
      <a href="entregas_proveedor.php" class="menu-link <?php echo ($current_page === 'entregas_proveedor.php') ? 'active' : ''; ?>">
        <span>🚚</span> <span>Enviar al CEDIS (Entregas)</span>
      </a>
      <a href="trazabilidad_proveedor.php" class="menu-link <?php echo ($current_page === 'trazabilidad_proveedor.php') ? 'active' : ''; ?>">
        <span>🔍</span> <span>Origen y Calidad</span>
      </a>
      <a href="reportes_proveedor.php" class="menu-link <?php echo ($current_page === 'reportes_proveedor.php') ? 'active' : ''; ?>">
        <span>📊</span> <span>Mis Reportes</span>
      </a>
      <a href="editar_perfil.php" class="menu-link <?php echo ($current_page === 'editar_perfil.php') ? 'active' : ''; ?>">
        <span>⚙️</span> <span>Mi Perfil y Granjas</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" style="text-decoration:none;">
        <button class="logout-btn">
          <span>🚪</span> Salir (Cerrar Sesión)
        </button>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    <header class="app-header">
      <div>
        <h1>Registrar Postura (Recolección Diaria)</h1>
        <p>Anota la cantidad de huevos frescos que has recolectado hoy en tus granjas.</p>
      </div>
      <button class="header-btn" onclick="abrirModalCrear()">
        <span>✚</span> Registrar Nueva Recolección
      </button>
    </header>

    <!-- Notificaciones -->
    <?php if (!empty($mensaje_exito)): ?>
      <div class="alert-container">
        <div class="alert alert-success"><span>✓</span> <?php echo htmlspecialchars($mensaje_exito); ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($mensaje_error)): ?>
      <div class="alert-container">
        <div class="alert alert-danger"><span>✗</span> <?php echo htmlspecialchars($mensaje_error); ?></div>
      </div>
    <?php endif; ?>

    <!-- Buscador interactivo -->
    <div class="section-search">
      <div class="search-input-wrapper">
        <input type="text" id="buscarProd" placeholder="Buscar por código de lote, granja o producto..." onkeyup="filtrarTabla()">
      </div>
    </div>

    <!-- Tabla Historial de Producción -->
    <div class="card" style="padding: 24px;">
      <h3>Historial de Postura Diaria (Lotes unificados)</h3>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Lote Diario</th>
              <th>Granja</th>
              <th>Fecha Recolección</th>
              <th style="text-align: center;">Huevos Recolectados</th>
              <th>Desglose Incubables</th>
              <th style="color: #c27c0e; text-align: center;">No Viables</th>
              <th style="color: #b02500; text-align: center;">Mermas</th>
              <th style="width: 140px; text-align: center;">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaCuerpo">
            <?php if (!empty($historial)): ?>
              <?php foreach ($historial as $row): 
                $en_entrega = (int)$row["en_entrega"];
                $is_editable = ($en_entrega === 0);
              ?>
                <tr class="row-produccion">
                  <td style="font-weight: 800; color: var(--primary); font-family: monospace; font-size: 13.5px;"><?php echo htmlspecialchars($row['codigo_lote']); ?></td>
                  <td>🚜 <?php echo htmlspecialchars($row['granja_nombre'] ?? 'Sin granja'); ?></td>
                  <td><?php echo date('d/m/Y', strtotime($row['fecha_produccion'])); ?></td>
                  <td style="font-weight: 800; text-align: center; color: var(--text-dark);"><?php echo number_format($row['huevos_recolectados']); ?> uds</td>
                  <td>
                    <div style="font-size: 13.5px; color: var(--text-dark); font-weight: 700;">
                      Medianos: <?php echo number_format($row['medianos']); ?> uds <span style="color: var(--text-medium); margin: 0 4px;">|</span> Jumbo: <?php echo number_format($row['jumbo']); ?> uds
                    </div>
                  </td>
                  <td style="color: #c27c0e; text-align: center; font-weight: 700;"><?php echo number_format($row['no_viable']); ?> uds</td>
                  <td style="color: #b02500; text-align: center; font-weight: 700;"><?php echo number_format($row['merma']); ?> uds</td>
                  <td style="text-align: center;">
                    <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                      <!-- Botón Ver Resumen (👁) -->
                      <button class="action-btn" style="background: rgba(23, 106, 33, 0.1); color: var(--secondary); border: 1px solid var(--secondary);" title="Ver Resumen de Lote" onclick='abrirModalResumen(<?php echo json_encode($row); ?>)'>👁</button>
                      
                      <?php if ($is_editable): ?>
                        <button class="action-btn action-btn-edit" title="Editar Lote Diario" onclick='abrirModalEditar(<?php echo json_encode($row); ?>)'>✎</button>
                        <button class="action-btn action-btn-delete" title="Eliminar Lote Diario" onclick="confirmarEliminarLote('<?php echo htmlspecialchars($row['codigo_lote']); ?>')">🗑</button>
                      <?php else: ?>
                        <span class="badge" style="background: #e2e8f0; color: #64748b; font-size: 11px; padding: 4px 8px; font-weight: 700;" title="Lote en proceso de entrega o ya entregado al CEDIS">Entregado</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" style="text-align: center; padding: 30px; color: var(--text-medium);">No tienes producciones registradas actualmente.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginación -->
      <div class="pagination-wrapper">
        <div class="pagination-text" id="paginacionTexto">MOSTRANDO HISTORIAL</div>
        <div class="pagination-buttons" id="paginacionContenedor"></div>
      </div>
    </div>

  </main>

  <!-- Mobile Nav -->
  <nav class="mobile-nav">
    <a href="dashboard_proveedor.php" class="mobile-nav-btn">
      <span>🚜</span>
      <span>Mi Resumen</span>
    </a>
    <a href="produccion_proveedor.php" class="mobile-nav-btn active">
      <span>🥚</span>
      <span>Postura</span>
    </a>
    <a href="inventario_proveedor.php" class="mobile-nav-btn">
      <span>🧺</span>
      <span>Almacén</span>
    </a>
    <a href="entregas_proveedor.php" class="mobile-nav-btn">
      <span>🚚</span>
      <span>Entregas</span>
    </a>
    <a href="editar_perfil.php" class="mobile-nav-btn">
      <span>⚙️</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

<!-- ==========================================
     MODALES (AGREGAR, EDITAR, ELIMINAR)
     ========================================== -->

<!-- Modal Agregar -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container" style="max-width: 580px;">
    <div class="modal-header">
      <div class="modal-title">Registrar Nueva Postura (Recolección Diaria)</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <?php if (empty($granjas)): ?>
      <div style="text-align: center; padding: 20px;">
        <span style="font-size:36px;">🚜</span>
        <h4 style="margin: 10px 0; color: #b02500;">Falta Registrar Granjas</h4>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin-bottom: 20px;">Debe registrar al menos una granja en "Mi Perfil" antes de registrar producción por trazabilidad.</p>
        <a href="editar_perfil.php" class="btn-submit" style="text-decoration:none;">Ir a Mi Perfil</a>
      </div>
    <?php else: ?>
      <form action="produccion_proveedor.php" method="POST" id="formAgregarPostura">
        <input type="hidden" name="accion" value="agregar">
        
        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
          <div class="form-group">
            <label>Granja de Origen *</label>
            <select name="granja_id" id="add_granja_id" required onchange="actualizarCodigoLoteAdd(); calculateViablesAdd();">
              <option value="">-- Selecciona Granja --</option>
              <?php foreach ($granjas as $g): ?>
                <option value="<?php echo $g['id']; ?>" data-identificacion="<?php echo htmlspecialchars($g["identificacion"]); ?>" data-stock="<?php echo $g['stock_cartones']; ?>">🚜 <?php echo htmlspecialchars($g["nombre"]); ?> (<?php echo $g["stock_cartones"]; ?> cartones)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Fecha de Recolección *</label>
            <input type="date" name="fecha_produccion" id="add_fecha_produccion" value="<?php echo date('Y-m-d'); ?>" required onchange="actualizarCodigoLoteAdd(); calculateViablesAdd();">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label>Nomenclatura de Lote Generado (Autocalculado)</label>
          <input type="text" id="add_codigo_lote_display" readonly style="background: rgba(0,0,0,0.04); font-weight: 800; color: var(--primary); letter-spacing: 0.5px; border: 1.5px solid var(--glass-border);" value="Selecciona Granja y Fecha">
        </div>

        <div style="margin: 10px 0 16px 0; border-bottom: 2px solid var(--primary); padding-bottom: 6px;">
          <h4 style="color: var(--text-dark); font-family: 'Plus Jakarta Sans', sans-serif; font-size: 15px; font-weight: 800;">Desglose Cuantitativo de Postura</h4>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label style="font-weight: 800; color: var(--text-dark);">1. Total de Huevos Recolectados (Dato General) *</label>
          <input type="number" name="huevos_recolectados" id="add_huevos_recolectados" min="1" placeholder="Cantidad total recolectada en el día" required oninput="calculateViablesAdd()" style="font-size: 15px; font-weight: 700; border-color: var(--secondary);">
        </div>

        <div class="size-section-row" style="background: rgba(255, 138, 0, 0.02); border: 1.5px solid var(--glass-border); padding: 16px; border-radius: 18px; margin-bottom: 16px;">
          <h5 style="color: var(--secondary); font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14px; font-weight: 800; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <span>🥚</span> Clasificación de Huevos
          </h5>
          
          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
            <div class="form-group">
              <label>Incubables Medianos (56-70g)</label>
              <input type="number" name="medianos" id="add_medianos" min="0" value="0" placeholder="Ej: 300" oninput="calculateViablesAdd()">
            </div>
            <div class="form-group">
              <label>Incubables Jumbo (>70g)</label>
              <input type="number" name="jumbo" id="add_jumbo" min="0" value="0" placeholder="Ej: 150" oninput="calculateViablesAdd()">
            </div>
          </div>
          
          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>No Viables (Chico, Pigmentados, Deformes)</label>
              <input type="number" name="no_viable" id="add_no_viable" min="0" value="0" placeholder="Ej: 50" oninput="calculateViablesAdd()">
            </div>
            <div class="form-group">
              <label>Mermas (Rotos / Estrellados / Dañados)</label>
              <input type="number" name="merma" id="add_merma" min="0" value="0" placeholder="Ej: 20" oninput="calculateViablesAdd()">
            </div>
          </div>
        </div>

        <!-- Indicadores y Validaciones -->
        <div style="margin-bottom: 16px;">
          <div id="add_validation_msg" style="font-size: 13px; padding: 10px 14px; border-radius: 12px; margin-bottom: 10px; display: none;"></div>
          
          <div style="font-size:13px; color:var(--secondary); font-weight:800; padding: 10px 14px; background: rgba(23, 106, 33, 0.06); border-radius: 12px; border: 1px dashed var(--secondary);" id="add_calc_cartones">
            Se usarán aproximadamente 0 cartones de empaque en total.
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label>Observaciones de Trazabilidad</label>
          <textarea name="observaciones" placeholder="Ej: Recolección limpia en nidos de pastoreo, alimentación orgánica."></textarea>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
          <button type="submit" class="btn-submit" id="btnSubmitAgregar" disabled>Registrar Postura y Lotes</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Editar -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container" style="max-width: 580px;">
    <div class="modal-header">
      <div class="modal-title">Editar Lote Diario (Eventualidad de Producción)</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="produccion_proveedor.php" method="POST" id="formEditarPostura">
      <input type="hidden" name="accion" value="editar">

      <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
        <div class="form-group">
          <label>Lote Diario (Fijo)</label>
          <input type="text" name="codigo_lote" id="edit_codigo_lote" readonly style="background: rgba(0,0,0,0.04); font-weight: 800; color: var(--primary);">
        </div>

        <div class="form-group">
          <label>Granja de Origen *</label>
          <select name="granja_id" id="edit_granja_id" required onchange="calculateViablesEdit()">
            <?php foreach ($granjas as $g): ?>
              <option value="<?php echo $g['id']; ?>" data-stock="<?php echo $g['stock_cartones']; ?>">🚜 <?php echo htmlspecialchars($g["nombre"]); ?> (<?php echo $g["stock_cartones"]; ?> cartones)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group" style="margin-bottom: 12px;">
        <label>Fecha de Recolección *</label>
        <input type="date" name="fecha_produccion" id="edit_fecha_produccion" required onchange="calculateViablesEdit()">
      </div>

      <div style="margin: 10px 0 12px 0; border-bottom: 2px solid var(--primary); padding-bottom: 6px;">
        <h4 style="color: var(--text-dark); font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14px; font-weight: 800;">Modificación Cuantitativa</h4>
      </div>

      <div class="form-group" style="margin-bottom: 12px;">
        <label style="font-weight: 800; color: var(--text-dark);">Total de Huevos Recolectados *</label>
        <input type="number" name="huevos_recolectados" id="edit_huevos_recolectados" min="1" required oninput="calculateViablesEdit()" style="font-weight: 700; border-color: var(--secondary);">
      </div>

      <div class="size-section-row" style="background: rgba(255, 138, 0, 0.02); border: 1.5px solid var(--glass-border); padding: 14px; border-radius: 18px; margin-bottom: 16px;">
        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
          <div class="form-group">
            <label>Incubables Medianos</label>
            <input type="number" name="medianos" id="edit_medianos" min="0" oninput="calculateViablesEdit()">
          </div>
          <div class="form-group">
            <label>Incubables Jumbo</label>
            <input type="number" name="jumbo" id="edit_jumbo" min="0" oninput="calculateViablesEdit()">
          </div>
        </div>
        
        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>No Viables (Chicos, etc.)</label>
            <input type="number" name="no_viable" id="edit_no_viable" min="0" oninput="calculateViablesEdit()">
          </div>
          <div class="form-group">
            <label>Mermas (Rotos / Dañados)</label>
            <input type="number" name="merma" id="edit_merma" min="0" oninput="calculateViablesEdit()">
          </div>
        </div>
      </div>

      <div style="margin: 10px 0 12px 0; border-bottom: 2px solid #b02500; padding-bottom: 6px;">
        <h4 style="color: #b02500; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14px; font-weight: 800;">Justificación de Auditoría Obligatoria</h4>
      </div>

      <div class="form-grid" style="grid-template-columns: 1fr; gap: 12px; margin-bottom: 16px;">
        <div class="form-group">
          <label>Motivo de Modificación *</label>
          <select name="motivo_modificacion" id="edit_motivo_modificacion" required style="border-color: #b02500;">
            <option value="">-- Selecciona el Motivo --</option>
            <option value="Error de captura">Error de captura original</option>
            <option value="Robo o pérdida">Robo o pérdida en almacén</option>
            <option value="Caída o rotura en almacén">Caída o rotura durante el manejo</option>
            <option value="Ajuste por reconteo">Diferencia detectada en reconteo</option>
            <option value="Otro">Otro motivo (especificar abajo)</option>
          </select>
        </div>

        <div class="form-group">
          <label>Justificación / Detalle de la Modificación *</label>
          <textarea name="observacion_detalle" id="edit_observacion_detalle" placeholder="Por ejemplo: Se detectó un error al capturar 20 huevos Medianos adicionales que en realidad correspondían a Jumbo..." required style="border-color: #b02500; height: 70px;"></textarea>
        </div>
      </div>

      <!-- Indicadores y Validaciones -->
      <div style="margin-bottom: 16px;">
        <div id="edit_validation_msg" style="font-size: 13px; padding: 10px 14px; border-radius: 12px; margin-bottom: 10px; display: none;"></div>
        
        <div style="font-size:13px; color:var(--secondary); font-weight:800; padding: 10px 14px; background: rgba(23, 106, 33, 0.06); border-radius: 12px; border: 1px dashed var(--secondary);" id="edit_calc_cartones">
          Se usarán aproximadamente 0 cartones de empaque.
        </div>
      </div>

      <div class="form-group" style="margin-bottom: 16px;">
        <label>Observaciones de Trazabilidad Generales</label>
        <textarea name="observaciones" id="edit_observaciones" style="height: 50px;"></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit" id="btnSubmitEditar" disabled>Guardar Cambios Auditados</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Resumen Premium -->
<div class="modal-overlay" id="modalResumen">
  <div class="modal-container" style="max-width: 550px;">
    <div class="modal-header" style="border-bottom: 2px solid var(--secondary);">
      <div class="modal-title" style="display: flex; align-items: center; gap: 8px;">
        <span>🔍</span> Resumen de Trazabilidad del Lote
      </div>
      <button class="modal-close" onclick="cerrarModal('modalResumen')">×</button>
    </div>
    
    <div class="modal-body" style="padding: 20px 0;">
      <div style="text-align: center; margin-bottom: 20px; background: rgba(255, 138, 0, 0.04); padding: 16px; border-radius: 18px; border: 1.5px solid var(--glass-border);">
        <span style="font-size: 11px; text-transform: uppercase; font-weight: 800; color: var(--text-medium); letter-spacing: 0.5px;">Código Único de Lote</span>
        <h2 id="resumen_codigo_lote" style="font-family: monospace; font-size: 22px; color: var(--primary); margin: 6px 0; font-weight: 800; letter-spacing: 1px;">-</h2>
        <span id="resumen_granja_nombre" style="font-size: 13.5px; font-weight: 700; color: var(--text-dark);">🚜 -</span>
      </div>

      <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
        <div style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border);">
          <span style="font-size: 11px; color: var(--text-medium); font-weight: 700;">Fecha de Recolección</span>
          <p id="resumen_fecha_produccion" style="font-size: 14px; font-weight: 800; color: var(--text-dark); margin: 4px 0 0 0;">-</p>
        </div>
        <div style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border);">
          <span style="font-size: 11px; color: var(--text-medium); font-weight: 700;">Total Huevos Recolectados</span>
          <p id="resumen_huevos_recolectados" style="font-size: 14px; font-weight: 800; color: var(--primary); margin: 4px 0 0 0;">-</p>
        </div>
      </div>

      <h4 style="font-size: 13px; text-transform: uppercase; font-weight: 800; color: var(--text-medium); letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
        <span>🥚</span> Distribución de Calidad
      </h4>
      
      <div style="border: 1.5px solid var(--glass-border); border-radius: 16px; overflow: hidden; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: rgba(0,0,0,0.01); border-bottom: 1px solid var(--glass-border); align-items: center;">
          <span style="font-size: 13px; font-weight: 700; color: var(--text-dark);">🥚 Medianos Viables (56g - 70g)</span>
          <strong id="resumen_medianos" style="font-size: 13px; color: var(--secondary); font-weight: 800;">- uds</strong>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: rgba(0,0,0,0.01); border-bottom: 1px solid var(--glass-border); align-items: center;">
          <span style="font-size: 13px; font-weight: 700; color: var(--text-dark);">🥚 Jumbo Viables (>70g)</span>
          <strong id="resumen_jumbo" style="font-size: 13px; color: var(--text-dark); font-weight: 800;">- uds</strong>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: rgba(0,0,0,0.01); border-bottom: 1px solid var(--glass-border); align-items: center;">
          <span style="font-size: 13px; font-weight: 700; color: #c27c0e;">🍳 No Viables (Descartados)</span>
          <strong id="resumen_no_viable" style="font-size: 13px; color: #c27c0e; font-weight: 800;">- uds</strong>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 10px 14px; background: rgba(0,0,0,0.01); align-items: center;">
          <span style="font-size: 13px; font-weight: 700; color: #b02500;">❌ Mermas (Roturas / Dañados)</span>
          <strong id="resumen_merma" style="font-size: 13px; color: #b02500; font-weight: 800;">- uds</strong>
        </div>
      </div>

      <div style="background: rgba(23, 106, 33, 0.05); border: 1px dashed var(--secondary); border-radius: 12px; padding: 10px 14px; font-size: 12.5px; color: var(--secondary); font-weight: 700; margin-bottom: 20px;" id="resumen_cartones_texto">
        Este lote dedujo un aproximado de - cartones de empaque.
      </div>

      <h4 style="font-size: 13px; text-transform: uppercase; font-weight: 800; color: var(--text-medium); letter-spacing: 0.5px; margin-bottom: 8px;">📝 Bitácora de Observaciones</h4>
      <div style="background: rgba(0,0,0,0.02); border: 1px solid var(--glass-border); padding: 12px; border-radius: 12px; max-height: 80px; overflow-y: auto;">
        <p id="resumen_observaciones" style="font-size: 13px; color: var(--text-dark); margin: 0; font-style: italic; line-height: 1.4;">Sin observaciones registradas.</p>
      </div>
    </div>

    <div class="modal-actions" style="margin-top: 10px;">
      <button type="button" class="btn-cancel" style="width: 100%; text-align: center;" onclick="cerrarModal('modalResumen')">Cerrar Detalle</button>
    </div>
  </div>
</div>

<!-- Modal Eliminar Lote Diario -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px; text-align: center;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Confirmar Eliminación de Lote Diario</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="produccion_proveedor.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="codigo_lote" id="delete_codigo_lote">
      
      <p style="color: var(--text-medium); font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar este lote diario de producción?<br>
        Esta acción eliminará el lote <strong id="delete_lote_str" style="color: var(--text-dark);"></strong> completo de forma permanente y devolverá los cartones de empaque correspondientes a la granja.
      </p>

      <div class="modal-actions" style="justify-content: center;">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit btn-danger">Eliminar Lote Completo</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts de paginación e interactividad -->
<script>
function abrirModalCrear() {
    document.getElementById('add_granja_id').value = '';
    document.getElementById('add_fecha_produccion').value = new Date().toISOString().split('T')[0];
    document.getElementById('add_codigo_lote_display').value = 'Selecciona Granja y Fecha';
    document.getElementById('add_huevos_recolectados').value = '';
    document.getElementById('add_medianos').value = 0;
    document.getElementById('add_jumbo').value = 0;
    document.getElementById('add_no_viable').value = 0;
    document.getElementById('add_merma').value = 0;
    
    const valMsg = document.getElementById('add_validation_msg');
    valMsg.style.display = 'none';
    
    document.getElementById('btnSubmitAgregar').disabled = true;
    
    const calcText = document.getElementById('add_calc_cartones');
    if (calcText) {
        calcText.textContent = 'Se usarán aproximadamente 0 cartones de empaque en total.';
    }
    
    document.getElementById('modalCrear').classList.add('active');
}

function actualizarCodigoLoteAdd() {
    const granjaSelect = document.getElementById('add_granja_id');
    const fechaInput = document.getElementById('add_fecha_produccion');
    const displayInput = document.getElementById('add_codigo_lote_display');
    
    if (granjaSelect.value && fechaInput.value) {
        const option = granjaSelect.options[granjaSelect.selectedIndex];
        const identificacion = option.getAttribute('data-identificacion') || 'GRJ';
        const fecha = fechaInput.value.replace(/-/g, '');
        displayInput.value = `${identificacion}-${fecha}-[Autocalculado]`;
    } else {
        displayInput.value = 'Selecciona Granja y Fecha';
    }
}

function calculateViablesAdd() {
    const totalRec = parseInt(document.getElementById('add_huevos_recolectados').value) || 0;
    const medianos = parseInt(document.getElementById('add_medianos').value) || 0;
    const jumbo = parseInt(document.getElementById('add_jumbo').value) || 0;
    const noViable = parseInt(document.getElementById('add_no_viable').value) || 0;
    const merma = parseInt(document.getElementById('add_merma').value) || 0;
    
    const granjaSelect = document.getElementById('add_granja_id');
    const valMsg = document.getElementById('add_validation_msg');
    const btnSubmit = document.getElementById('btnSubmitAgregar');
    const calcText = document.getElementById('add_calc_cartones');
    
    const totalCalculado = medianos + jumbo + noViable + merma;
    const cartones = Math.ceil((medianos + jumbo) / 30);
    
    if (calcText) {
        calcText.textContent = `Se usarán aproximadamente ${cartones} cartones de empaque en total.`;
    }
    
    if (totalRec <= 0) {
        valMsg.style.display = 'none';
        btnSubmit.disabled = true;
        return;
    }
    
    if (totalCalculado === totalRec) {
        // Verificar stock de cartones de la granja seleccionada
        let stockSuficiente = true;
        if (granjaSelect.value) {
            const option = granjaSelect.options[granjaSelect.selectedIndex];
            const stock = parseInt(option.getAttribute('data-stock')) || 0;
            if (stock < cartones) {
                stockSuficiente = false;
            }
        }
        
        if (!stockSuficiente) {
            valMsg.style.display = 'block';
            valMsg.style.background = 'rgba(176, 37, 0, 0.08)';
            valMsg.style.color = '#b02500';
            valMsg.style.border = '1px solid rgba(176, 37, 0, 0.2)';
            valMsg.textContent = `✗ Insumos insuficientes: La granja requiere ${cartones} cartones, pero tiene menos stock disponible.`;
            btnSubmit.disabled = true;
        } else {
            valMsg.style.display = 'block';
            valMsg.style.background = 'rgba(23, 106, 33, 0.08)';
            valMsg.style.color = '#176a21';
            valMsg.style.border = '1px solid rgba(23, 106, 33, 0.2)';
            valMsg.textContent = '✓ La suma coincide exactamente con el total recolectado.';
            btnSubmit.disabled = false;
        }
    } else {
        valMsg.style.display = 'block';
        valMsg.style.background = 'rgba(176, 37, 0, 0.08)';
        valMsg.style.color = '#b02500';
        valMsg.style.border = '1px solid rgba(176, 37, 0, 0.2)';
        valMsg.textContent = `✗ La suma de Medianos, Jumbo, No Viables y Mermas (${totalCalculado}) no coincide con el total recolectado (${totalRec}).`;
        btnSubmit.disabled = true;
    }
}

function calculateViablesEdit() {
    const totalRec = parseInt(document.getElementById('edit_huevos_recolectados').value) || 0;
    const medianos = parseInt(document.getElementById('edit_medianos').value) || 0;
    const jumbo = parseInt(document.getElementById('edit_jumbo').value) || 0;
    const noViable = parseInt(document.getElementById('edit_no_viable').value) || 0;
    const merma = parseInt(document.getElementById('edit_merma').value) || 0;
    
    const valMsg = document.getElementById('edit_validation_msg');
    const btnSubmit = document.getElementById('btnSubmitEditar');
    const calcText = document.getElementById('edit_calc_cartones');
    
    const totalCalculado = medianos + jumbo + noViable + merma;
    const cartones = Math.ceil((medianos + jumbo) / 30);
    
    if (calcText) {
        calcText.textContent = `Se usarán aproximadamente ${cartones} cartones de empaque.`;
    }
    
    if (totalRec <= 0) {
        valMsg.style.display = 'none';
        btnSubmit.disabled = true;
        return;
    }
    
    if (totalCalculado === totalRec) {
        valMsg.style.display = 'block';
        valMsg.style.background = 'rgba(23, 106, 33, 0.08)';
        valMsg.style.color = '#176a21';
        valMsg.style.border = '1px solid rgba(23, 106, 33, 0.2)';
        valMsg.textContent = '✓ La suma coincide exactamente con el total recolectado.';
        btnSubmit.disabled = false;
    } else {
        valMsg.style.display = 'block';
        valMsg.style.background = 'rgba(176, 37, 0, 0.08)';
        valMsg.style.color = '#b02500';
        valMsg.style.border = '1px solid rgba(176, 37, 0, 0.2)';
        valMsg.textContent = `✗ La suma de Medianos, Jumbo, No Viables y Mermas (${totalCalculado}) no coincide con el total recolectado (${totalRec}).`;
        btnSubmit.disabled = true;
    }
}

function abrirModalEditar(data) {
    document.getElementById('edit_codigo_lote').value = data.codigo_lote;
    document.getElementById('edit_granja_id').value = data.granja_id;
    document.getElementById('edit_fecha_produccion').value = data.fecha_produccion;
    document.getElementById('edit_huevos_recolectados').value = data.huevos_recolectados;
    document.getElementById('edit_medianos').value = data.medianos;
    document.getElementById('edit_jumbo').value = data.jumbo;
    document.getElementById('edit_no_viable').value = data.no_viable;
    document.getElementById('edit_merma').value = data.merma;
    document.getElementById('edit_observaciones').value = data.observaciones || '';
    
    document.getElementById('edit_motivo_modificacion').value = '';
    document.getElementById('edit_observacion_detalle').value = '';
    
    calculateViablesEdit();
    
    document.getElementById('modalEditar').classList.add('active');
}

function abrirModalResumen(data) {
    document.getElementById('resumen_codigo_lote').textContent = data.codigo_lote;
    document.getElementById('resumen_granja_nombre').textContent = '🚜 ' + (data.granja_nombre || 'Sin granja');
    
    const partes = data.fecha_produccion.split('-');
    const fechaFormateada = `${partes[2]}/${partes[1]}/${partes[0]}`;
    document.getElementById('resumen_fecha_produccion').textContent = fechaFormateada;
    
    document.getElementById('resumen_huevos_recolectados').textContent = parseInt(data.huevos_recolectados).toLocaleString() + ' uds';
    document.getElementById('resumen_medianos').textContent = parseInt(data.medianos).toLocaleString() + ' uds';
    document.getElementById('resumen_jumbo').textContent = parseInt(data.jumbo).toLocaleString() + ' uds';
    document.getElementById('resumen_no_viable').textContent = parseInt(data.no_viable).toLocaleString() + ' uds';
    document.getElementById('resumen_merma').textContent = parseInt(data.merma).toLocaleString() + ' uds';
    document.getElementById('resumen_observaciones').textContent = data.observaciones || 'Sin observaciones registradas.';
    
    const cartones = Math.ceil((parseInt(data.medianos) + parseInt(data.jumbo)) / 30);
    document.getElementById('resumen_cartones_texto').textContent = `Este lote dedujo un aproximado de ${cartones} cartones de empaque.`;
    
    document.getElementById('modalResumen').classList.add('active');
}

function confirmarEliminarLote(codigo_lote) {
    document.getElementById('delete_codigo_lote').value = codigo_lote;
    document.getElementById('delete_lote_str').textContent = codigo_lote;
    document.getElementById('modalEliminar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda y Paginación
let paginaActual = 1;
const registrosPorPagina = 8;
let filtroQuery = "";

function actualizarVistaPaginacion() {
    const rows = document.querySelectorAll('#tablaCuerpo .row-produccion');
    const matchingRows = [];

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(filtroQuery)) {
            matchingRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    const totalRegistros = matchingRows.length;
    const totalPaginas = Math.ceil(totalRegistros / registrosPorPagina) || 1;

    if (paginaActual > totalPaginas) paginaActual = totalPaginas;
    if (paginaActual < 1) paginaActual = 1;

    const inicio = (paginaActual - 1) * registrosPorPagina;
    const fin = inicio + registrosPorPagina;

    matchingRows.forEach((row, index) => {
        if (index >= inicio && index < fin) {
            row.style.display = 'table-row';
        } else {
            row.style.display = 'none';
        }
    });

    const mostradosInicio = totalRegistros > 0 ? inicio + 1 : 0;
    const mostradosFin = Math.min(fin, totalRegistros);
    document.getElementById('paginacionTexto').textContent = 
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} REGISTROS`;

    // Renderizar botones de paginación
    const contenedor = document.getElementById('paginacionContenedor');
    contenedor.innerHTML = "";
    for (let p = 1; p <= totalPaginas; p++) {
        const btn = document.createElement('button');
        btn.className = (p === paginaActual) ? 'page-btn active' : 'page-btn';
        btn.textContent = p;
        btn.onclick = () => {
            paginaActual = p;
            actualizarVistaPaginacion();
        };
        contenedor.appendChild(btn);
    }
}

function filtrarTabla() {
    filtroQuery = document.getElementById('buscarProd').value.toLowerCase();
    paginaActual = 1;
    actualizarVistaPaginacion();
}

function updateCartonCalc(val, elemId) {
    const qty = parseInt(val) || 0;
    const cartons = Math.ceil(qty / 30);
    document.getElementById(elemId).textContent = `Se usarán aproximadamente ${cartons} cartones de empaque.`;
}

document.addEventListener("DOMContentLoaded", () => {
    actualizarVistaPaginacion();
});
</script>

<!-- ASISTENTE VIRTUAL ACCESIBLE: DOÑA ALI PARA GRANJEROS -->
<div id="dona-ali-container" style="position:fixed; bottom:80px; right:24px; z-index:99999; display:flex; flex-direction:column; align-items:flex-end; gap:12px; font-family:inherit;">
  
  <!-- Burbuja de Diálogo de Doña Ali -->
  <div id="dona-ali-bubble" style="display:none; width:300px; background:white; border-radius:20px; border:1px solid rgba(213, 164, 112, 0.25); box-shadow:0 10px 30px rgba(0,0,0,0.15); padding:20px; flex-direction:column; gap:12px; transition:all 0.3s ease;">
    <!-- Encabezado de la Burbuja -->
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(213,164,112,0.15); padding-bottom:8px;">
      <span style="font-weight:800; color:var(--text-dark); font-size:14px; display:inline-flex; align-items:center; gap:6px;">👵 Doña Ali Asistente</span>
      <button onclick="toggleDonaAliBubble()" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-medium); line-height:1;">×</button>
    </div>
    
    <!-- Texto de Respuesta -->
    <p id="dona-ali-text" style="margin:0; font-size:13px; color:var(--text-medium); line-height:1.6; font-weight:700;">¡Hola, granjero! Soy Doña Ali. Estoy aquí para ayudarte a manejar tus huevos y registros. Haz clic en una pregunta o cuéntame qué necesitas.</p>
    
    <!-- Opciones / Preguntas frecuentes -->
    <div id="dona-ali-options" style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
      <button onclick="askDonaAli('postura')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🥚 ¿Cómo registro recolección?</button>
      <button onclick="askDonaAli('lotes')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">📦 ¿Qué es un lote?</button>
      <button onclick="askDonaAli('envio')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚚 ¿Cómo envío a la ciudad?</button>
      <button onclick="askDonaAli('insumos')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚜 ¿No me deja guardar postura?</button>
    </div>

    <!-- Controles de Voz -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; border-top:1px solid rgba(213,164,112,0.1); padding-top:8px;">
      <button id="dona-ali-speak-btn" onclick="readDonaResponse()" style="background:none; border:none; cursor:pointer; font-size:12px; font-weight:800; color:var(--text-medium);" title="Escuchar respuesta">🔊 Escuchar</button>
      <button id="dona-ali-listen-btn" onclick="listenToUser()" style="background:none; border:none; cursor:pointer; font-size:12px; font-weight:800; color:#b02500;" title="Hablarle a Doña Ali">🎙️ Hablarle</button>
    </div>
  </div>

  <!-- Botón Circular Flotante (Trigger) -->
  <button onclick="toggleDonaAliBubble()" style="width:60px; height:60px; border-radius:50%; background:linear-gradient(135deg, var(--primary), #e07b00); border:none; color:white; font-size:28px; cursor:pointer; box-shadow:0 8px 25px rgba(255,138,0,0.35); display:grid; place-items:center; transition:transform 0.2s ease-in-out;" onmouseover="this.style.transform='scale(1.08)';" onmouseout="this.style.transform='scale(1)';">
    👵
  </button>
</div>

<script>
  let donaSpeechUtterance = null;
  let voiceRecognition = null;

  function toggleDonaAliBubble() {
      const bubble = document.getElementById('dona-ali-bubble');
      if (bubble.style.display === 'none' || bubble.style.display === '') {
          bubble.style.display = 'flex';
          speakText("Hola, granjero. Soy Doña Ali. ¿En qué te ayudo hoy con tus tareas del campo?");
      } else {
          bubble.style.display = 'none';
          if (window.speechSynthesis) {
              window.speechSynthesis.cancel();
          }
      }
  }

  function askDonaAli(topic) {
      const textEl = document.getElementById('dona-ali-text');
      let response = '';

      if (topic === 'postura') {
          response = 'Para registrar tus huevos recolectados del día, haz clic en el botón naranja que dice "Registrar Postura" en la esquina de arriba. Indica de qué granja provienen, el tipo de huevo, la cantidad y la fecha. El sistema calculará cuántos cartones utilizarás de forma automática.';
      } else if (topic === 'lotes') {
          response = 'Cada vez que registras una postura, el sistema crea un Lote de huevos de forma automática. Este lote tiene una etiqueta especial y una fecha de caducidad calculada de 3 días desde su postura para asegurar la frescura de los huevos.';
      } else if (topic === 'envio') {
          response = 'Ve a la pestaña "Enviar al CEDIS (Entregas)". Presiona "Solicitar Recolección", elige el centro de distribución de EcoAli al que quieres enviar y la fecha. Luego, marca las casillas de los lotes de tu almacén que vas a mandar e ingresa la cantidad de cada uno.';
      } else if (topic === 'insumos') {
          response = 'Para asegurar la calidad, cada postura debe registrarse empacada en cartones. Si tu granja tiene 0 o pocos cartones disponibles, no te dejará guardar. Puedes reabastecer cartones yendo a "Mi Perfil y Granjas" en la sección de tus granjas.';
      } else {
          response = 'Hola, hijo. Soy Doña Ali. Estoy aquí para ayudarte a manejar tus registros de postura y tus envíos.';
      }

      textEl.textContent = response;
      speakText(response);
  }

  let selectedFemaleVoice = null;
  function loadVoices() {
      if (!window.speechSynthesis) return;
      const voices = window.speechSynthesis.getVoices();
      if (!voices || voices.length === 0) return;
      const spanishVoices = voices.filter(v => v.lang.includes('es') || v.lang.includes('ES'));
      let found = spanishVoices.find(v => {
          const nameLower = v.name.toLowerCase();
          return nameLower.includes('sabina') || 
                 nameLower.includes('dalia') || 
                 nameLower.includes('yolanda') || 
                 nameLower.includes('helena') || 
                 nameLower.includes('laura') || 
                 nameLower.includes('hilda') || 
                 nameLower.includes('female') ||
                 nameLower.includes('zira') ||
                 nameLower.includes('dona') ||
                 nameLower.includes('mujer') ||
                 nameLower.includes('google');
      });
      if (!found) {
          found = spanishVoices.find(v => {
              const nameLower = v.name.toLowerCase();
              return !nameLower.includes('david') && 
                     !nameLower.includes('raul') && 
                     !nameLower.includes('carlos') && 
                     !nameLower.includes('jorge') && 
                     !nameLower.includes('male') && 
                     !nameLower.includes('hombre');
          });
      }
      if (!found && spanishVoices.length > 0) {
          found = spanishVoices[0];
      }
      selectedFemaleVoice = found;
  }
  if (window.speechSynthesis) {
      window.speechSynthesis.onvoiceschanged = loadVoices;
      loadVoices();
  }

  function speakText(text) {
      if (!window.speechSynthesis) return;
      window.speechSynthesis.cancel();
      
      donaSpeechUtterance = new SpeechSynthesisUtterance(text);
      donaSpeechUtterance.lang = 'es-MX';
      
      if (!selectedFemaleVoice) {
          loadVoices();
      }
      if (selectedFemaleVoice) {
          donaSpeechUtterance.voice = selectedFemaleVoice;
      }
      window.speechSynthesis.speak(donaSpeechUtterance);
  }

  function readDonaResponse() {
      const text = document.getElementById('dona-ali-text').textContent;
      speakText(text);
  }

  function listenToUser() {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
          alert("Tu navegador no soporta el reconocimiento de voz. Te recomiendo usar Google Chrome.");
          return;
      }

      const listenBtn = document.getElementById('dona-ali-listen-btn');
      listenBtn.textContent = "🎙️ Escuchando...";
      listenBtn.style.color = "var(--secondary)";

      voiceRecognition = new SpeechRecognition();
      voiceRecognition.lang = 'es-MX';
      voiceRecognition.interimResults = false;
      voiceRecognition.maxAlternatives = 1;

      voiceRecognition.start();

      voiceRecognition.onresult = function(event) {
          const phrase = event.results[0][0].transcript.toLowerCase();
          console.log("Usuario dijo: " + phrase);
          
          if (phrase.includes('postura') || phrase.includes('recolect') || phrase.includes('huevo')) {
              askDonaAli('postura');
          } else if (phrase.includes('lote') || phrase.includes('paquete')) {
              askDonaAli('lotes');
          } else if (phrase.includes('envio') || phrase.includes('enviar') || phrase.includes('cedis')) {
              askDonaAli('envio');
          } else if (phrase.includes('insumo') || phrase.includes('carton') || phrase.includes('no me deja')) {
              askDonaAli('insumos');
          } else {
              const textEl = document.getElementById('dona-ali-text');
              textEl.textContent = 'Te escuché: "' + phrase + '". ¿Me puedes preguntar de otra forma, por favor?';
              speakText(textEl.textContent);
          }
      };

      voiceRecognition.onspeechend = function() {
          listenBtn.textContent = "🎙️ Hablarle";
          listenBtn.style.color = "#b02500";
          voiceRecognition.stop();
      };

      voiceRecognition.onerror = function(event) {
          listenBtn.textContent = "🎙️ Hablarle";
          listenBtn.style.color = "#b02500";
          console.log("Error de reconocimiento: " + event.error);
      };
  }
</script>

</body>
</html>
