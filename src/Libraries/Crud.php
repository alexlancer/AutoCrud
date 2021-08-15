<?php namespace Alexlancer\Autocrud\Libraries;

use Alexlancer\Autocrud\Libraries\Crud_core;
use CodeIgniter\HTTP\RequestInterface;

class Crud extends Crud_core
{
    function __construct($params, RequestInterface $request)
    {
        parent::__construct($params, $request);
    }

    function form()
    {

        $form = '';
        $post = $this->request->getPost();

        if (isset($post['form']) && $post['form'] == $this->form_id) {

            //This $_POST['form'] is just to check if the $_POST values are from
            // form submition and not search or something else
            unset($post['form']);

            if (isset($post['files']))
                unset($post['files']);

            if ($_FILES && isset($_FILES['files']))
                unset($_FILES['files']);

            if (isset($post['u_password']) && $post['u_password'] == '' && $post['u_password'] == $post['u_password_confirm']) {

                $pass = false;
                unset($post['u_password_confirm']);
                unset($post['u_password']);
            }


            // echo '<pre>';
            // print_r($_FILES);
            // echo '<pre>';
            // exit;

            $file_fields = [];
            foreach ($_FILES as $file_field_name => $file_field_value) {
                $file_fields[] = $file_field_name;
            }

            //Create rules
            $novalidation = true;
            $this->validator = service('validation');
            //Fields to be unsetted before insert or update
            $unsets = [];
            //Store ['field1', 'field2', '...']  to be hashed with password_hash
            $toHash = [];
            $otherTables = [];
            $otherTableValues = [];

            foreach ($this->fields as $field => $params) {
                if (($this->action == 'add' && !isset($params['only_edit']) && @$params['only_edit'] !== TRUE) ||
                    ($this->action == 'edit' && !isset($params['only_add']) && @$params['only_add'] !== TRUE)
                ) {
                    $theLabel = $this->get_label($this->fields[$field], $field);

                    if (isset($params['type']) && ($params['type'] == 'file' || $params['type'] == 'files')) {
                        $fileRulesArr = [];
                        $multi = $params['type'] == 'file' ? false : true;
                        if (isset($params['required']) && $params['required'] === TRUE) {
                            if ($multi) {
                                $fileFieldName = $field . '.0';
                                $unsets[] = $field;
                            } else
                                $fileFieldName = $field;

                            $fileRulesArr[] = 'uploaded[' . $fileFieldName . ']';
                        }

                        if (isset($params['max_size']))
                            $fileRulesArr[] = 'max_size[' . $field . ',' . $params['max_size'] . ']';

                        if (isset($params['is_image']) && $params['is_image'] === TRUE)
                            $fileRulesArr[] = 'is_image[' . $field . ']';

                        if (isset($params['ext_in']))
                            $fileRulesArr[] = 'ext_in[' . $field . ',' . $params['ext_in'] . ']';

                        $fileRules = implode('|', $fileRulesArr);

                        $this->validator->setRule($field, $theLabel, $fileRules);
                    } elseif ((isset($params['required']) && $params['required'] === TRUE) || (isset($params['type']) && $params['type'] == 'hidden')) {
                        $novalidation = false;
                        $this->validator->setRule($field, $theLabel, 'required');
                    }


                    if (isset($params['unique']) && isset($params['unique'][0]) && isset($params['unique'][1]) && $params['unique'][0] === TRUE) {
                        $unique_field = $params['unique'][1];
                        if (!isset($this->current_values) || $this->current_values->{$unique_field} != $post[$unique_field]) {
                            $novalidation = false;
                            $this->validator->setRule($field, $theLabel, 'is_unique[' . $this->table . '.' . $unique_field . ']');
                        }
                    }
                    if ((isset($params['confirm']) && $params['confirm'] === TRUE)) {

                        $novalidation = false;
                        $this->validator->setRule($field, $theLabel, 'trim');
                        $this->validator->setRule($field . '_confirm', $theLabel . ' confirmation', 'matches[' . $field . ']');
                        //Unset confirmation field
                        $unsets[] = $field . '_confirm';
                    }

                    //Check if relational values should be saved in different table
                    $otherTable = false;
                    if (isset($params['relation'])) {
                        $relOptions = $params['relation'];
                        $otherTable = $relOptions['save_table'] ?? false;
                    }
                    if ($otherTable) {
                        $otherTables[] = $otherTable;

                        //echo 'test';exit;
                        // $novalidation = false;
                        $otherTableValues[$otherTable] = [
                            'parent_field' => $relOptions['parent_field'],
                            'child_field' => $relOptions['child_field'],
                            'values' => $post[$field] ?? [],
                            //'current_field_name' => $field
                        ];


                        $unsets[] = $field;
                    }

                    //check relational table save

                    if (isset($params['password_hash']) && $params['password_hash'] === TRUE) {
                        $toHash = [$field];
                    }
                }
            }


            if ($this->validator->withRequest($this->request)->run() || $novalidation) //|| empty($this->fields)
            {

                $insertedFilesAffectedRows = false;
                $affected = false;
                $toDelete = false;
                $toInsert = false;

                foreach ($unsets as $unset) {
                    unset($post[$unset]);
                }

                //Convert any array post to string
                foreach ($post as $key => $post_input) {
                    if (is_array($post_input)) {
                        if ($post_input[0] == '0') {
                            unset($post_input[0]);
                        }
                        $post[$key] = implode(',', $post_input);
                    }
                }

                foreach ($toHash as $hashIt) {
                    $post[$hashIt] = password_hash($post[$hashIt], PASSWORD_DEFAULT);
                }

                //If file fields exist do the uplaod
                $filesData = [];
                if ($file_fields) {
                    foreach ($file_fields as $file_field) {
                        $fileFieldOptions = $this->fields[$file_field];
                        //Single file (meaning that the name will be saved in the same table)

                        if ($fileFieldOptions['type'] == 'file') {
                            $uploadedFileName = $this->fileHandler($file_field, $this->fields[$file_field]);
                            if ($uploadedFileName)
                                $post[$file_field] = $uploadedFileName;
                        } elseif ($fileFieldOptions['type'] == 'files')
                            $filesData[$file_field] = $fileFieldOptions;
                    }
                }

                if ($this->action == 'add') {
                    if (!$this->current_values) {
                        $this->id = $this->model->insertItem($this->table, $post);
                        if ($this->id) {
                            $this->flash('success', 'Successfully Added');
                        }
                    }
                } elseif ($this->action == 'edit') {

                    //Prepare data
                    //remove any foreign fields by compairing to schema
                    $update_data = [];
                    foreach ($this->schema as $schema_field) {
                        if (isset($post[$schema_field->Field])) {
                            $update_data[$schema_field->Field] = $post[$schema_field->Field];
                        }
                    }


                    $affected = $this->model->updateItem($this->table, [$this->id_field => $this->id], $update_data);

                    //Do not set flash if there is $otherTables (from relational options)
                    if (!$otherTables && !$filesData) {

                        if ($affected == 1)
                            $this->flash('success', label('app.flash.SuccessUpdate'));
                        else
                            $this->flash('warning', label('app.flash.NoUpdateNoChanges'));
                    }
                }


                if ($otherTables) {

                    foreach ($otherTables as $otherTable) {
                        $exisingRelations = [];
                        // Preparing existing relations from another (relational) table
                        if ($this->current_values) {

                            $otherWhere = [$otherTableValues[$otherTable]['parent_field'] => $this->current_values->{$this->get_primary_key_field_name()}];
                            $exisingRelationItems = $this->model->getRelationItems($otherTable, $otherWhere);
                            if ($exisingRelationItems) {
                                foreach ($exisingRelationItems as $exisingRelationItem) {
                                    $exisingRelations[] = $exisingRelationItem->{$otherTableValues[$otherTable]['child_field']};
                                }
                            }
                        }
                        //Preparing submited values
                        $newRelations = $otherTableValues[$otherTable]['values'];
                        $newRelations = (is_array($newRelations) ? $newRelations : []);
                        //Exclude same data
                        $toDelete = array_diff($exisingRelations, $newRelations);
                        $toInsert = array_diff($newRelations, $exisingRelations);

                        if ($toDelete) {
                            $where = [$otherTableValues[$otherTable]['parent_field'] => $this->id];
                            $this->model->deleteItems($otherTable, $where, $otherTableValues[$otherTable]['child_field'], $toDelete);
                        }

                        if ($toInsert) {
                            foreach ($toInsert as $toInsertItem) {
                                $newTempRelationData = [];
                                $newTempRelationData[] = [
                                    $otherTableValues[$otherTable]['parent_field'] => $this->id,
                                    $otherTableValues[$otherTable]['child_field'] => $toInsertItem
                                ];

                                $this->model->batchInsert($otherTable, $newTempRelationData);
                            }
                        }

                        // if (!$filesData) {
                        //     if ($toDelete || $toInsert || $affected)
                        //         $this->flash('success', label('app.flash.SuccessUpdate') );
                        //     else
                        //         $this->flash('warning', label('app.flash.NoUpdateNoChanges') );
                        // }
                    }
                    // $otherTableValues = [
                    //     'parent_field' => $relOptions['save_table_parent_id'],
                    //     'child_field' => $relOptions['save_table_child_id'],
                    //     'values' => $post[$field]
                    // ];


                }
                $insertedFilesAffectedRows = false;
                if ($filesData) {
                    foreach ($filesData as $fileDataKey => $fileDataOptions) {
                        $insertedFilesAffectedRows = $this->filesHandler($fileDataKey, $fileDataOptions);
                    }
                }

                if ($insertedFilesAffectedRows || $affected || $toDelete || $toInsert)
                    $this->flash('success', label('app.flash.SuccessUpdate'));
                else {
                    if ($this->action == 'edit')
                        $this->flash('warning', label('app.flash.NoUpdateNoChanges'));
                }

                return ['redirect' => $this->createEditUrl($this->id)];
            } else {
                // if ($_POST) {

                //     echo '<pre>';
                //     print_r($_POST);
                //     echo '</pre>';
                //     exit;
                // }

                // echo '<div>';
                //  print_r($this->validator->getErrors());
                // echo '<div>';
                // exit();
            }
        }

        $form .= '<div class="card card-primary">';

        if ($this->add_card_header && $this->action == 'add') {
            $form .= '<div class="card-header">
                <h3 class="card-title">' . $this->form_title_add . '</h3>
              </div>';
        } else if ($this->edit_card_header && $this->action == 'edit') {
            $form .= '<div class="card-header">
                <h3 class="card-title">' .  $this->form_title_update . '</h3>
              </div>';
        }

        //Form url
        if ($this->action == 'add') {
            $formActionUrl = $this->createAddUrl();
        } else {
            $formActionUrl = $this->createEditUrl($this->id);
        }


        if ($this->multipart) {
            $form .= form_open_multipart($formActionUrl);
        } else {
            $form .= form_open($formActionUrl);
        }
        $form .= '<div class="card-body"><input type="hidden" name="form" value="' . $this->form_id . '"><div class="row">';

        $fields = $this->fields;
        foreach ($this->schema as $field) {

            $f = $field;
            if ($f->Extra == 'auto_increment') {
                continue;
            }

            if (isset($fields[$f->Field]['only_edit']) && $fields[$f->Field]['only_edit'] && $this->action == 'add') {
                continue;
            }

            if (isset($fields[$f->Field]['only_add']) && $fields[$f->Field]['only_add'] && $this->action == 'edit') {
                continue;
            }

            if (isset($fields[$f->Field]['type']) && $fields[$f->Field]['type'] == 'unset') {
                continue;
            }

            $label = $this->get_label($field);
            $field_type = (isset($fields[$f->Field]['type']) ? $fields[$f->Field]['type'] : $this->get_field_type($f));

            if (($field_type == 'enum' || $field_type == 'select2') && !isset($fields[$f->Field]['values'])) {
                preg_match("/^enum\(\'(.*)\'\)$/", $f->Type, $matches);
                $fields[$f->Field]['values'] = explode("','", $matches[1]);

                if ($field_type == 'select2') :
                    $field_type = 'select2';
                else :
                    $field_type = 'select';
                endif;
            }
            //Check if relation table is set for the field
            if (isset($fields[$f->Field]['relation'])) {
                $rel = $fields[$f->Field]['relation'];

                $rel_table = $rel['table'];
                $rel_order_by = $rel['order_by'];
                $rel_where =  $rel['where'] ?? false;

                $rel_order = $rel['order'];
                $fields[$f->Field]['values'] = $this->model->getRelationItems($rel_table, $rel_where, $rel_order_by, $rel_order);
            }

            $field_values = $fields[$f->Field]['values'] ?? null;

            $field_method = 'field_' . $field_type;



            //Checking if helper text is set for this field
            $helperText = '';
            if (isset($fields[$f->Field]['helper']))
                $helperText = '<small  class="form-text text-muted">' . $fields[$f->Field]['helper'] . '</small>';



            $class = "col-sm-12";
            if (isset($fields[$f->Field]['class']))
                $class = $fields[$f->Field]['class'];

            $hidden = false;
            if (isset($fields[$f->Field]['type']) && $fields[$f->Field]['type'] == 'hidden')
                $hidden = true;
            else {
                $form .= "<div class='$class'><div class='form-group'>";
            }

            //execute appropriate function

            $form .= $this->$field_method($f->Field, $label, $fields[$f->Field] ?? null, $field_values, $class);
            if (!$hidden) {
                $form .=  "$helperText</div></div>";
            }
        }


        $form .= '</div></div><div class="card-footer">
              <button type="submit" class="btn btn-primary">' . ($this->action == 'add' ? $this->form_submit : $this->form_submit_update) . '</button>
              </div>' . form_close() . '</div>';

        if ($this->multipart) {
            $form .= '<script type="text/javascript">
                    $(document).ready(function () {
                    bsCustomFileInput.init();
                    });
                    </script>';
        }

        return $form;
    }

    protected function field_select2($field_type, $label, $field_params, $values)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        $input = '<select ' . $required . ' class="form-control" id="' . $field_type . '" name="' . $field_type . '"><option></option>';

        foreach ($values as $value) {
            $input .= '<option value="' . $value . '" ' . set_select($field_type, $value, (isset($this->current_values->{$field_type}) && $this->current_values->{$field_type} == $value ? TRUE : FALSE)) . '>' . label('app.string.' . ucfirst($value)) . '</option>';
        }
        $input .= '</select><script>$(document).ready(function() {
    $("#' . $field_type . '").select2({theme: "bootstrap4",width:"100%"});
    });</script>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function field_dropdown2($field_type, $label, $field_params, $values)
    {
        $required = (isset($field_params['required']) && $field_params['required'] ? ' required ' : '');
        //randomize_id for select2
        $rand_number = mt_rand(1545645, 15456546);
        $rid = $field_type . '_' . $rand_number;
        $input = '<select  class="form-control" ' . $required . ' id="' . $rid . '" name="' . $field_type . '"><option></option>';
        $pk = $field_params['relation']['primary_key'];
        $display = $field_params['relation']['display'];
        foreach ($values as $value) {
            if (is_array($display)) {
                $display_val = '';
                foreach ($display as $disp) {
                    $display_val .= $value->{$disp} . ' ';
                }
                $display_val = trim($display_val);
            } else {
                $display_val = $value->{$display};
            }
            $input .= '<option value="' . $value->{$pk} . '" ' . set_select($field_type, $value->{$pk}, (isset($this->current_values->{$field_type}) && $this->current_values->{$field_type} == $value->{$pk} ? TRUE : FALSE)) . '>' . label('app.string.' . $display_val) . '</option>';
        }

        $input .= '</select><script>$(document).ready(function() {
        $("#' . $rid . '").select2({theme: "bootstrap4",width:"100%"});
        });</script>';
        return $this->input_wrapper($field_type, $label, $input, $required);
    }

    protected function items_table($columns = null, $items, $pagination)
    {


        $fields = $this->fields;
        $primary_key = $this->get_primary_key_field_name();


        $table = '<div class="row">
                    <div class="col-12">
                         <div class="card">';

        if ($this->table_card_header) {
            $table .= '<div class="card-header">
                <h3 class="card-title"> ' . $this->table_title . '</h3>';
            if ($this->add_item) {
                $addUrl = $this->createAddUrl();
                $table .= '<div class="card-tools">
            <a class="btn btn-primary btn-sm" href="' . $addUrl . '">' . $this->form_title_add . '</a>
            </div>';
            }
            $table .= '</div>';
        }
        $table .= '<div class="card-body table-responsive p-0">';

        $table .= '<table class="table table-hover text-nowrap" id="' . $this->table . '"><thead><tr>';
        if ($columns) {
            foreach ($columns as $column) {


                if (is_array($column)) {
                    $label = $column['label'];
                    $th_class = ucfirst(str_replace('_', ' ', $column['label']));
                } else {
                    $label = $fields[$column]['label'] ?? ucfirst(str_replace('_', ' ', $column));
                    $th_class = $column;
                }

                // order by selected column, 
                // if clicking second time, 
                // change to acs order
                $order = $this->getBaseUrl();
                $orderBy = '/?desc=';
                $textColor = 'text-secondary';
                if ($this->request->getGet('desc')) {
                    if ($this->request->getGet('desc') == $th_class) {
                        $textColor = 'text-dark';
                        $orderBy = '/?acs=';
                    }
                } elseif ($this->request->getGet('acs')) {
                    if ($this->request->getGet('acs') == $th_class) {
                        $textColor = 'text-dark';
                        $orderBy = '/?desc=';
                    }
                }
                $table .= '<th class="th-' . $th_class . '">' . $label;
                if (!is_array($column)) {
                    $table .=  '<a href="' . $order . $orderBy . $th_class . '" class="ml-1 ' . $textColor . '"><i class="fa fa-sort"></i></a>';
                }
                $table .= '</th>';
            }
        } else {

            foreach ($this->schema as $item) {
                $label = $fields[$item->Field]['label'] ?? ucfirst(str_replace('_', ' ', $item->Field));
                $table .= '<th class="th-' . $item->Field . '">' . $label . '</th>';
            }
        }

        $table .= '<th class="th-action" width="10%">' . label('app.string.Actions') . '</th>';
        $table .= '</tr></thead><tbody>';


        //Search fields
        if ($this->search) {
            // $table .= '<tr>' . form_open();
            $table .= '<tr>' . form_open('', ["method" => "get"]);
            if ($columns) {

                foreach ($columns as $column) {
                    //check date fields

                    if (!is_array($column) && isset($fields[$column]) && isset($fields[$column]['relation'])) {

                        $rel = $fields[$column]['relation'];

                        $pk = $fields[$column]['relation']['primary_key'];


                        $rel_table = $rel['table'];
                        $rel_order_by = $rel['order_by'];
                        $rel_where =  $rel['where'] ?? false;

                        $rel_order = $rel['order'];
                        $fields[$column]['values'] = $this->model->getRelationItems($rel_table, $rel_where, $rel_order_by, $rel_order);

                        $field_values = $fields[$column]['values'] ?? null;

                        $rid = mt_rand(1545645, 15456546);

                        $table .= '<td><select  class="form-control" id="' . $rid . '" name="' . $column . '"><option></option>';
                        $display = $fields[$column]['relation']['display'];
                        foreach ($field_values as $value) {

                            $tmp_value = (array)$value;
                            $option_pk = $tmp_value[$pk];
                            if (count($display) > 1) {

                                $option = '';
                                foreach ($display as $disp) {
                                    $display_val = $disp;
                                    $option .= $tmp_value[$display_val] . ' ';
                                }
                            } else {

                                $display_val = $display[0];
                                $option = $tmp_value[$display_val];
                            }
                            $option = trim($option);
                            $get_value = '';

                            if ($this->request->getGet('table_search')) {
                                if ($this->request->getGet($column) != '') {
                                    $get_value = $this->request->getGet($column);
                                }
                            }

                            $selected = '';

                            if ($get_value != '' && $option_pk === $get_value) {
                                $selected = ' selected ';
                            }

                            $table .= '<option value="' . $option_pk . '" ' .  $selected  . '>' . $option . '</option>';
                        }
                        $table .= '</select></td><script>$(document).ready(function() {
                $("#' . $rid . '").select2({theme: "bootstrap4",width:"100%"});
            });</script>';
                    } elseif (is_array($column)) {

                        if ($newCol = $column['search'] ?? false) {
                            $field_type = $column['search_field_type'] ?? 'text';
                            $label = $column['label'];
                            $table .= '<td><input type="' . $field_type . '" name="' . $newCol . '" class="form-control pull-right" value="' . strip_tags($this->request->getGet($newCol)) . '" placeholder="' . $label . '"></td>';
                        } else {
                            $table .= '<td></td>';
                            continue;
                        }
                    } else {
                        if (isset($fields[$column]['type']) && strpos($fields[$column]['type'], 'date') !== FALSE) {
                            $field_type = 'date';
                        } else {
                            //check this field type in schema
                            foreach ($this->schema as $field_types) {
                                if ($field_types->Field != $column)
                                    continue;

                                if (strpos($field_types->Type, 'date') !== FALSE)
                                    $field_type = 'date';
                                else
                                    $field_type = 'text';
                            }
                        }
                        $label = $fields[$column]['label'] ?? ucfirst(str_replace('_', ' ', $column));
                        $table .= '<td><input type="' . $field_type . '" name="' . $column . '" class="form-control pull-right" value="' . strip_tags($this->request->getGet($column)) . '" placeholder="' . $label . '"></td>';
                    }
                }
            } else {
                foreach ($this->schema as $item) {
                    if (strpos($item->Type, 'date') !== FALSE)
                        $field_type = 'date';
                    else
                        $field_type = 'text';

                    $label = $fields[$item->Field]['label'] ?? ucfirst(str_replace('_', ' ', $item->Field));
                    $table .= '<td><input type="' . $field_type . '" name="' . $item->Field . '" class="form-control pull-right" value="' . set_value($item->Field) . '" placeholder="' . $label . '"></td>';
                }
            }
            $table .= '<input type="hidden" name="table_search" class="form-control pull-right" value="' . $this->table . '">';
            $table .= '<td class="text-center"><input class="btn  btn-default" type="submit" value="' . label('app.string.Search') . '"></td></tr></form>';
        }



        // Result items

        foreach ($items as $item) {
            $table .= '<tr class="row_item" >';
            $fields = $this->fields;
            if ($columns) {
                foreach ($columns as $column) {

                    if (is_array($column)) {
                        $display_val = $this->{$column['callback']}($item);
                    } elseif ($relation = $fields[$column]['relation'] ?? false) {
                        $relTable = $relation['save_table'] ?? false;
                        $relItems = false;
                        if ($relTable) {
                            $joinTable = $relation['table'];
                            $joinTablePk = $relation['primary_key'];

                            $joinString = $relTable . '.' . $relation['child_field'] . '=' . $joinTable . '.' . $joinTablePk;
                            $relWhere = [$relation['parent_field'] => $item->{$primary_key}];
                            $relItems = $this->model->getRelationItemsJoin($relTable, $relWhere, $joinTable, $joinString);
                        }

                        $display_val = '';

                        if ($relItems) {
                            $tempRelName = [];
                            foreach ($relItems as $relItem) {
                                if (is_array($relation['display'])) {
                                    $tempName = '';
                                    foreach ($relation['display'] as $rel_display) {
                                        $tempName .= $relItem->{$rel_display} . ' ';
                                    }
                                    $tempRelName[] = trim($tempName);

                                    //
                                } else
                                    $tempRelName[] = $relItem->{$relation['display']};

                                $display_val = implode(', ', $tempRelName);
                            }
                        } elseif ($relItems === false) {
                            if (is_array($relation['display'])) {

                                foreach ($relation['display'] as $rel_display) {
                                    $display_val .= $item->{$rel_display} . ' ';
                                }
                                $display_val = trim($display_val);
                                //
                            } else
                                $display_val = $item->{$relation['display']};
                        }
                    } else
                        $display_val = $item->{$column};

                    // *******
                    if (!is_array($column)) {
                        if ($column == 'q_scaled') {
                            $display_val = label('app.string.' . str_replace(' ', '', $display_val));
                        } elseif (strpos($column, 'status')) {
                            $display_val = label('app.string.' . $display_val);
                        }
                        if ($column == 'q_direction') {
                            $display_val = label('app.string.' . str_replace(' ', '', $display_val));
                        }
                    }

                    // *******

                    $table .= '<td>' . $display_val . '</td>';
                }
            } else {
                foreach ($this->schema as $column) {
                    $col_name = $column->Field;
                    $relation = $fields[$col_name]['relation'] ?? false;
                    if ($relation) {
                        $display_val = '';
                        if (is_array($fields[$col_name]['relation']['display'])) {

                            foreach ($fields[$col_name]['relation']['display'] as $rel_display) {
                                $display_val .= $item->{$rel_display} . ' ';
                            }
                            $display_val = trim($display_val);
                            //
                        } else
                            $display_val = $item->{$fields[$col_name]['relation']['display']};
                    } else
                        $display_val = $item->{$col_name};


                    $table .= '<td>' . $display_val . '</td>';
                }
            }
            $editUrl = $this->createEditUrl($item->{$primary_key});

            $table .= '<td class="text-center"><a href="' . $editUrl . '" class="btn btn-success btn-sm">' . label('app.string.Edit') . '</a></td>';
            $table .= '</tr>';
        }
        $table .= '</tbody></table></div>';
        $table .= '<div class="card-footer clearfix">';
        if ($this->request->getGet('table_search')) {

            $table .= '<a href="' . $this->createBaseUrl() . '" class="btn btn-warning btn-xs"><i class="fa fa-times"></i> Clear filters</a>';
            $table .=  $pagination;
        } else {
            $table .=  $pagination;
        }

        $table .=  '</div></div></div></div>';

        return $table;
    }

    function getBaseUrl()
    {
        return $this->createBaseUrl();
    }
    private function createBaseUrl()
    {
        $url = '';

        if ($this->base) {
            $url .= '/' . $this->base . '/';
            $url = str_replace('//', '/', $url);
        }

        if ($this->main_segment) {
            $url .= '/' . $this->main_segment . '/';
        } else {
            $url .= '/' . $this->table . '/';
        }
        $url = str_replace('//', '/', $url);

        return $url;
    }

    private function createEditUrl($id)
    {
        $url = $this->createBaseUrl();
        $url .= '/' . $this->edit_segment . '/' . $id;
        $url = str_replace('//', '/', $url);

        return $url;
    }

    private function createAddUrl()
    {
        $url = $this->createBaseUrl();
        $url .= '/add';
        $url = str_replace('//', '/', $url);

        return $url;
    }

    public function callback_get_invoice($item)
    {

        $html = '';

        if ($item->o_status == 'Awaiting Payment' || $item->o_status == 'Completed') {
            $html .= '<a href="/invoices/download/' . $item->o_hash . '/" class="download download-invoice-table text-primary"><i class="fas fa-download"></i></a>';
        }

        return $html;
    }

    public function callback_get_checkbox_invoice($item)
    {

        $html = '
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input invoice_select" id="invoice_select-' . $item->o_id . '" name="invoice_select" >
        </div>';

        return $html;
    }
}
