<?php

/**************************************************************************
 PLUGIN CLASSES
 --------------------------------------------------------------------------
   - BP_XProfile_Group -- Profile group management
   - BP_XProfile_Field -- Profile field management
   - BP_XProfile_ProfileData -- Profile data management
   - BP_XProfile_Picture -- Profile picture management
 -------------------------------------------------------------------------- 
 **************************************************************************/

Class BP_XProfile_Group {
	var $id = null;
	var $name;
	var $description;
	var $can_delete;
	var $fields;
	
	var $base_prefix;
	var $table_name_groups;
	var $table_name_fields;
	
	function bp_xprofile_group( $id = null ) {
		global $bp_xprofile_table_name_groups, $bp_xprofile_table_name_fields;
 
		$this->table_name_groups = $bp_xprofile_table_name_groups;
		$this->table_name_fields = $bp_xprofile_table_name_fields;
		
		if ( $id ) {
			if ( bp_core_validate($id) ) {
				$this->populate($id);
			}
		}
	}
	
	function populate( $id ) {
		global $wpdb;
		
		$sql = $wpdb->prepare("SELECT * FROM $this->table_name_groups WHERE id = %d", $id);

		if ( $group = $wpdb->get_row($sql) ) {
			$this->id = $group->id;
			$this->name = $group->name;
			$this->description = $group->description;
			$this->can_delete = $group->can_delete;
			
			// get the fields for this group.
			$this->fields = $this->get_fields();
		}

	}

	function save() {
		global $wpdb;

		if ( $this->id != null ) {
			$sql = $wpdb->prepare("UPDATE $this->table_name_groups SET name = %s, description = %s WHERE id = %d", $this->name, $this->description, $this->id);
		} else {
			$sql = $wpdb->prepare("INSERT INTO $this->table_name_groups (name, description, can_delete) VALUES (%s, %s, 1)", $this->name, $this->description);		
		}
		
		if ( $wpdb->query($sql) === false )
			return false;
		
		return true;
	}
	
	function delete() {
		global $wpdb;
		
		if ( !$this->can_delete )
			return false;
		
		$sql = $wpdb->prepare("DELETE FROM $this->table_name_groups WHERE id = %d", $this->id);

		if ( $wpdb->query($sql) === false) {
			return false;
		} else {
			// Now the group is deleted, remove the group's fields.
			if ( BP_XProfile_Field::delete_for_group($this->id) ) {
				// Now delete all the profile data for the groups fields
				for ( $i = 0; $i < count($this->fields); $i++ ) {	
					BP_XProfile_ProfileData::delete_for_field($this->fields[$i]->id);
				}
			}
			
			return true;
		}
	}
	
	function get_fields() {
		global $wpdb;

		// Get field ids for the current group.
		$sql = $wpdb->prepare("SELECT id, type FROM $this->table_name_fields WHERE group_id = %d AND parent_id = 0 ORDER BY id", $this->id);

		if(!$fields = $wpdb->get_results($sql))			
			return false;

		return $fields;
	}
	
	function render_admin_form() {
		global $message;

		if ( $this->id == null ) {
			$title = __('Add Group');
			$action = "admin.php?page=xprofile_settings&amp;mode=add_group";
		} else {
			$title = __('Edit Group');
			$action = "admin.php?page=xprofile_settings&amp;mode=edit_group&amp;group_id=" . $this->id;			
		}
	?>
		<div class="wrap">
		
			<h2><?php echo $title; ?></h2>
			<br />
			
			<?php
				if ( $message != '' ) {
					$type = ( $type == 'error' ) ? 'error' : 'updated';
			?>
				<div id="message" class="<?php echo $type; ?> fade">
					<p><?php echo $message; ?></p>
				</div>
			<?php } ?>
			
			<form action="<?php echo $action; ?>" method="post">
				
				<div id="titlediv">
					<label for="group_name"><?php _e("Group Name") ?></label>
					<div>
						<input type="text" name="group_name" id="group_name" value="<?php echo $this->name ?>" style="width:50%" />
					</div>
				</div>
				
				<p class="submit" style="text-align: left">
					<input type="submit" name="saveGroup" value="<?php echo $title; ?> &raquo;" />
				</p>
			
			</form>
		</div>
		
		<?php
	}
	
	/** Static Functions **/
	
	function get_all( $hide_empty = false ) {
		global $wpdb, $bp_xprofile_table_name_groups, $bp_xprofile_table_name_fields;

		if ( $hide_empty ) {
			$sql = $wpdb->prepare("SELECT DISTINCT g.* FROM $bp_xprofile_table_name_groups g INNER JOIN $bp_xprofile_table_name_fields f ON g.id = f.group_id ORDER BY g.id ASC");
		} else {
			$sql = $wpdb->prepare("SELECT * FROM $bp_xprofile_table_name_groups ORDER BY id ASC");
		}

		if ( !$groups_temp = $wpdb->get_results($sql) )
			return false;
			
		for ( $i = 0; $i < count($groups_temp); $i++ ) {
			$group = new BP_XProfile_Group($groups_temp[$i]->id);
			$groups[] = $group;
		}

		return $groups;
	}
	
	function admin_validate() {
		global $message;
		
		// Validate Form
		if ( $_POST['group_name'] == '' ) {
			$message = __('Please make sure you give the group a name.');
			return false;
		} else {
			return true;
		}
	}
}


Class BP_XProfile_Field {
	var $id;
	var $group_id;
	var $parent_id;
	var $type;
	var $name;
	var $desc;
	var $is_required;
	var $can_delete;
	
	var $data;
	var $message = null;
	var $message_type = 'err';
	
	var $table_name_groups;
	var $table_name_fields;

	function bp_xprofile_field( $id = null, $user_id = null, $get_data = true ) {
		global $bp_xprofile_table_name_groups, $bp_xprofile_table_name_fields;
		
		$this->table_name_groups = $bp_xprofile_table_name_groups;
		$this->table_name_fields = $bp_xprofile_table_name_fields;	
		
		if ( $id ) {
			$this->populate( $id, $user_id, $get_data );
		}
	}
	
	function populate( $id, $user_id, $get_data ) {
		global $wpdb, $userdata;
		
		if ( is_null($user_id) ) {
			$user_id = $userdata->ID;
		}
		
		$sql = $wpdb->prepare("SELECT * FROM $this->table_name_fields WHERE id = %d", $id);
	
		if ( $field = $wpdb->get_row($sql) ) {
			$this->id = $field->id;
			$this->group_id = $field->group_id;
			$this->parent_id = $field->parent_id;
			$this->type = $field->type;
			$this->name = $field->name;
			$this->desc = $field->description;
			$this->is_required = $field->is_required;
			$this->can_delete = $field->can_delete;
			
			if ( $get_data ) {
				$this->data = $this->get_field_data($user_id);
			}
		}
	}

	function delete() {
		global $wpdb;
		
		$sql = $wpdb->prepare("DELETE FROM $this->table_name_fields WHERE id = %d OR parent_id = %d", $this->id, $this->id);

		if ( $wpdb->query($sql) === false )
			return false;
		
		// delete the data in the DB for this field
		BP_XProfile_ProfileData::delete_for_field($this->id);
		
		return true;
	}
	
	function save() {
		global $wpdb;

		if ( $this->id != null ) {
			$sql = $wpdb->prepare("UPDATE $this->table_name_fields SET group_id = %d, parent_id = 0, type = %s, name = %s, description = %s, is_required = %d WHERE id = %d", $this->group_id, $this->type, $this->name, $this->desc, $this->is_required, $this->id);
		} else {
			$sql = $wpdb->prepare("INSERT INTO $this->table_name_fields	(group_id, parent_id, type, name, description, is_required) VALUES (%d, 0, %s, %s, %s, %d)", $this->group_id, $this->type, $this->name, $this->desc, $this->is_required);
		}
		
		if ( $wpdb->query($sql) !== false ) {
			// Only do this if we are editing an existing field
			if ( $this->id != null ) {
				// Remove any radio or dropdown options for this
				// field. They will be re-added if needed.
				// This stops orphan options if the user changes a
				// field from a radio button field to a text box. 
				$this->delete_children();
			}
			
			// Check to see if this is a selectbox or radio button field.
			// We need to add the options to the db, if it is.
			if ( $this->type == 'radio' || $this->type == 'selectbox' || $this->type == 'checkbox' ) {
				if ( $this->id ) {
					$parent_id = $this->id;
				} else {
					$parent_id = $wpdb->insert_id;	
				}

				if ( $this->type == "radio" ) {
					$options = $_POST['radio_option'];
				} else if ( $this->type == "selectbox" ) {
					$options = $_POST['select_option'];
				} else if ( $this->type == "checkbox" ) {
					$options = $_POST['checkbox_option'];
				}
				
				for ( $i = 0; $i < count($options); $i++ ) {
					$option_value = $options[$i];

					if ( $option_value != "" ) { 
						// don't insert an empty option.
						$sql = $wpdb->prepare("INSERT INTO $this->table_name_fields	(group_id, parent_id, type, name, description, is_required)	VALUES (%d, %d, 'option', %s, '', 0)", $this->group_id, $parent_id, $option_value);

						if ( $wpdb->query($sql) === false ) {
							return false;
							
							// @TODO 
							// Need to go back and reverse what has been entered here.
						}
					}						
				}
				return true;
			
			} else {
				return true;
			}
		}
		else
		{
			return false;
		}
	}
	
	function get_edit_html( $value = null ) {
		global $image_base;
		
		$asterisk = '';
		if ( $this->is_required ) {
			$asterisk = '* ';
		}
		
		$error_class = '';
		if ( $this->message ) {
			$this->message = '<p class="' . $this->message_type . '">' . $this->message . '</p>';
			$message_class = ' class="' . $this->message_type . '"';
		}
		
		if ( !is_null($value) ) {
			$this->data->value = $value;
		}
		
		switch ( $this->type ) {
			case 'textbox':
				$html .= '<label for="field_' . $this->id . '">' . $asterisk . $this->name . ':</label>';
				$html .= $this->message . '<input type="text" name="field_' . $this->id . '" id="field_' . $this->id . '" value="' . $this->data->value . '" />';
				$html .= '<span class="desc">' . $this->desc . '</span>';
			break;
			
			case 'textarea':
				$html .= '<label for="field_' . $this->id . '">' . $asterisk . $this->name . ':</label>';
				$html .= $this->message . '<textarea rows="5" cols="40" name="field_' . $this->id . '" id="field_' . $this->id . '">' . $this->data->value . '</textarea>';
				$html .= '<span class="desc">' . $this->desc . '</span>';
			break;
			
			case 'selectbox':
				$options = $this->get_children();

				$html .= '<label for="field_' . $this->id . '">' . $asterisk . $this->name . ':</label>';
				$html .= $this->message . '<select name="field_' . $this->id . '" id="field_' . $this->id . '">';
					for ( $k = 0; $k < count($options); $k++ ) {
						$option_value = BP_XProfile_ProfileData::get_value($options[$k]->parent_id);
	
						if ( $option_value == $options[$k]->name ) {
							$selected = ' selected="selected"';
						} else {
							$selected = '';
						}
						
						$html .= '<option' . $selected . ' value="' . $options[$k]->name . '">' . $options[$k]->name . '</option>';
					}
				$html .= '</select>';
				$html .= '<span class="desc">' . $this->desc . '</span>';
			break;
			
			case 'radio':
				$options = $this->get_children();
				
				$html .= '<div class="radio" id="field_' . $this->id . '"><span>' . $asterisk . $this->name . ':</span>' . $this->message;
				for ( $k = 0; $k < count($options); $k++ ) {
					$option_value = BP_XProfile_ProfileData::get_value($options[$k]->parent_id);

					if ( $option_value == $options[$k]->name ) {
						$selected = ' checked="checked"';
					} else {
						$selected = '';
					}
					
					$html .= '<label><input' . $selected . ' type="radio" name="field_' . $this->id . '" id="option_' . $options[$k]->id . '" value="' . $options[$k]->name . '"> ' . $options[$k]->name . '</label>';
				}
				
				$html .= '<span class="desc">' . $this->desc . '</span>';				
				$html .= '</div>';
				
				if ( !$this->is_required ) {
					$html .= '<a href="javascript:clear(\'field_' . $this->id . '\');"><img src="' . $image_base . '/cross.gif" alt="Clear" /> Clear</a>';
				}
				
			break;
			
			case 'checkbox':
				$options = $this->get_children();
				
				$html .= '<div class="checkbox" id="field_' . $this->id . '"><span>' . $asterisk . $this->name . ':</span>' . $this->message;
				
				$option_values = BP_XProfile_ProfileData::get_value($options[0]->parent_id);
				$option_values = unserialize($option_values);
				
				for ( $k = 0; $k < count($options); $k++ ) {	
					for ( $j = 0; $j < count($option_values); $j++ ) {
						if ( $option_values[$j] == $options[$k]->name ) {
							$selected = ' checked="checked"';
							break;
						}
					}
					
					$html .= '<label><input' . $selected . ' type="checkbox" name="field_' . $this->id . '[]" id="field_' . $options[$k]->id . '_' . $k . '" value="' . $options[$k]->name . '"> ' . $options[$k]->name . '</label>';
					$selected = '';
				}
				
				$html .= '<span class="desc">' . $this->desc . '</span>';				
				$html .= '</div>';
				
			break;
			
			case 'datebox':
				if ( $this->data->value != '' ) {
					$day = date("j", $this->data->value);
					$month = date("F", $this->data->value);
					$year = date("Y", $this->data->value);
					$default_select = ' selected="selected"';
				}
				
				$html .= '<div id="field_' . $this->id . '">';
				$html .= '<label for="field_' . $this->id . '_day">' . $asterisk . $this->name . ':</label>';
				
				$html .= $this->message . '
				<select name="field_' . $this->id . '_day" id="field_' . $this->id . '_day">';
				$html .= '<option value=""' . $default_select . '>--</option>';
				
				for ( $i = 1; $i < 32; $i++ ) {
					if ( $day == $i ) { 
						$selected = ' selected = "selected"'; 
					} else {
						$selected = '';
					}
					$html .= '<option value="' . $i .'"' . $selected . '>' . $i . '</option>';
				}
				
				$html .= '</select>';
				
				$months = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
								'September', 'October', 'November', 'December');
				
				$html .= '
				<select name="field_' . $this->id . '_month" id="field_' . $this->id . '_month">';
				$html .= '<option value=""' . $default_select . '>------</option>';
				
				for ( $i = 0; $i < 12; $i++ ) {
					if ( $month == $months[$i] ) {
						$selected = ' selected = "selected"';
					} else {
						$selected = '';
					}
					
					$html .= '<option value="' . $months[$i] . '"' . $selected . '>' . $months[$i] . '</option>';
				}

				$html .= '</select>';
				
				$html .= '
				<select name="field_' . $this->id . '_year" id="field_' . $this->id . '_year">';
				$html .= '<option value=""' . $default_select . '>----</option>';
								
				for ( $i = date( 'Y', time() ); $i > 1899; $i-- ) {
					if ( $year == $i ) {
						$selected = ' selected = "selected"'; 
					} else {
						$selected = '';
					}
				
					$html .= '<option value="' . $i .'"' . $selected . '>' . $i . '</option>';
				}
				
				$html .= '</select>';
				$html .= '<span class="desc">' . $this->desc . '</span>';
				$html .= '</div>';
				
			break;
		}
		
		return $html;
	}
	
	function get_field_data($user_id) {
		return new BP_XProfile_ProfileData($this->id, $user_id);
	}
	
	function get_children() {
		global $wpdb;
		
		$sql = $wpdb->prepare("SELECT * FROM $this->table_name_fields WHERE parent_id = %d AND group_id = %d", $this->id, $this->group_id);

		if ( !$children = $wpdb->get_results($sql) )
			return false;
		
		return $children;
	}
	
	function delete_children() {
		global $wpdb;

		$sql = $wpdb->prepare("DELETE FROM $this->table_name_fields	WHERE parent_id = %d", $this->id);

		$wpdb->query($sql);
	}
		
	function render_admin_form( $message = '' ) {
		if ( $this->id == null ) {
			$title = __('Add Field');
			$action = "admin.php?page=xprofile_settings&amp;group_id=" . $this->group_id . "&amp;mode=add_field";
		} else {
			$title = __('Edit Field');
			$action = "admin.php?page=xprofile_settings&amp;mode=edit_field&amp;group_id=" . $this->group_id . "&amp;field_id=" . $this->id;			
			$options = $this->get_children();
		}
		
	?>
	
	<div class="wrap">
		
		<h2><?php echo $title; ?></h2>
		<br />
		
		<?php
			if ( $message != '' ) {
		?>
			<div id="message" class="error fade">
				<p><?php echo $message; ?></p>
			</div>
		<?php } ?>
		
		<form action="<?php echo $action ?>" method="post">

				<label for="title">* <?php _e("Field Title") ?></label>
				<div>
					<input type="text" name="title" id="title" value="<?php echo $this->name ?>" style="width:50%" />
				</div>
				<p></p>
				<label for="description"><?php _e("Field Description") ?></label>
				<div>
					<textarea name="description" id="description" rows="5" cols="60"><?php echo $this->desc ?></textarea>
				</div>
				<p></p>
				<label for="required">* <?php _e("Is This Field Required?") ?></label>
				<div>
					<select name="required" id="required">
						<option value="0"<?php if ( $this->is_required == '0' ) { ?> selected="selected"<?php } ?>>Not Required</option>
						<option value="1"<?php if ( $this->is_required == '1' ) { ?> selected="selected"<?php } ?>>Required</option>
					</select>
				</div>
				<p></p>
				<label for="fieldtype">* <?php _e("Field Type") ?></label>
				<div>
					<select name="fieldtype" id="fieldtype" onchange="show_options(this.value)">
						<option value="textbox"<?php if ( $this->type == 'textbox' ) {?> selected="selected"<?php } ?>>Text Box</option>
						<option value="textarea"<?php if ( $this->type == 'textarea' ) {?> selected="selected"<?php } ?>>Multi-line Text Box</option>
						<option value="datebox"<?php if ( $this->type == 'datebox' ) {?> selected="selected"<?php } ?>>Date Selector</option>
						<option value="radio"<?php if ( $this->type == 'radio' ) {?> selected="selected"<?php } ?>>Radio Buttons</option>
						<option value="selectbox"<?php if ( $this->type == 'selectbox' ) {?> selected="selected"<?php } ?>>Drop-down Select Box</option>
						<option value="checkbox"<?php if ( $this->type == 'checkbox' ) {?> selected="selected"<?php } ?>>Checkboxes</option>
					</select>
				</div>
			
			<div id="radio" style="<?php if ( $this->type != 'radio' ) {?>display: none;<?php } ?> margin-left: 15px;">
				<p><?php _e('Please enter the options for this radio button field.') ?></p>
				<?php
				if ( !empty($options) ) {
					for ( $i = 0; $i < count($options); $i++ ) { ?>
						<p><?php _e('Option') ?> <?php echo $i+1 ?>: 
						   <input type="text" name="radio_option[]" id="radio_option<?php echo $i+1 ?>" value="<?php echo $options[$i]->name ?>" />
						</p>
				<?php } ?>
					<input type="hidden" name="radio_option_number" id="radio_option_number" value="<?php echo $i+1 ?>" />
				<?php } else { ?>
					<p><?php _e('Option') ?> 1: <input type="text" name="radio_option[]" id="radio_option1" /></p>
					<input type="hidden" name="radio_option_number" id="radio_option_number" value="2" />
				<?php } ?>
				<div id="radio_more"></div>
				<p><a href="javascript:add_option('radio')"><?php _e('Add Another Option') ?></a></p>
			</div>
			
			<div id="select" style="<?php if ( $this->type != 'selectbox' ) { ?>display: none;<?php } ?> margin-left: 15px;">
				<p><?php _e('Please enter the options for drop-down select box') ?></p>
				<?php
				if ( !empty($options) ) {
					for ( $i = 0; $i < count($options); $i++ ) { ?>
						<p><?php _e('Option') ?> <?php echo $i + 1 ?>: 
						   <input type="text" name="select_option[]" id="select_option<?php echo $i+1 ?>" value="<?php echo $options[$i]->name ?>" />
						</p>
				<?php } ?>
					<input type="hidden" name="select_option_number" id="select_option_number" value="<?php echo $i+1 ?>" />
				<?php } else { ?>
					<p><?php _e('Option') ?> 1: <input type="text" name="select_option[]" id="select_option1" /></p>
					<input type="hidden" name="select_option_number" id="select_option_number" value="2" />
				<?php } ?>
				<div id="select_more"></div>					
				<p><a href="javascript:add_option('select')"><?php _e('Add Another Option') ?></a></p>
			</div>
			
			<div id="checkbox" style="<?php if ( $this->type != 'checkbox' ) { ?>display: none;<?php } ?> margin-left: 15px;">
				<p><?php _e('Please enter the values for each checkbox.') ?></p>
				<?php
				if ( !empty($options) ) {
					for ( $i = 0; $i < count($options); $i++ ) { ?>
						<p><?php _e('Option') ?> <?php echo $i + 1 ?>: 
						   <input type="text" name="checkbox_option[]" id="checkbox_option<?php echo $i+1 ?>" value="<?php echo $options[$i]->name ?>" />
						</p>
				<?php } ?>
					<input type="hidden" name="checkbox_option" id="checkbox_option" value="<?php echo $i+1 ?>" />
				<?php } else { ?>
					<p><?php _e('Option') ?> 1: <input type="text" name="checkbox_option[]" id="checkbox_option1" /></p>
					<input type="hidden" name="checkbox_option_number" id="checkbox_option_number" value="2" />
				<?php } ?>
				<div id="checkbox_more"></div>					
				<p><a href="javascript:add_option('checkbox')"><?php _e('Add Another Option') ?></a></p>
			</div>		
							
			<p class="submit" style="float: left;">
					&nbsp;<input type="submit" value="<?php _e("Save") ?> &raquo;" name="saveField" id="saveField" style="font-weight: bold" />
					 <?php _e('or') ?> <a href="admin.php?page=xprofile_settings" style="color: red"><?php _e('Cancel') ?></a>
			</p>
			
			<div class="clear"></div>
			
		</form>

	</div>
	
	<?php
	}
	
	/** Static Functions **/

	function get_signup_fields() {
		global $wpdb, $bp_xprofile_table_name_fields, $bp_xprofile_table_name_groups;
		
		$sql = $wpdb->prepare("SELECT f.id FROM $bp_xprofile_table_name_fields AS f, $bp_xprofile_table_name_groups AS g WHERE g.name = 'Basic' AND f.parent_id = 0	AND g.id = f.group_id ORDER BY f.id");

		if ( !$temp_fields = $wpdb->get_results($sql) )
			return false;
		
		for ( $i = 0; $i < count($temp_fields); $i++ ) {
			$fields[] = new BP_XProfile_Field( $temp_fields[$i]->id, null, false );
		}
		
		return $fields;
	}

	function admin_validate() {
		global $message;
		
		// Validate Form
		if ( $_POST['title'] == '' || $_POST['required'] == '' || $_POST['fieldtype'] == '' ) {
			$message = __('Please make sure you fill out all required fields.');
			return false;
		} else if ($_POST['fieldtype'] == 'radio' && empty($_POST['radio_option'][0]) ) {
			$message = __('Radio button field types require at least one option. Please add options below.');	
			return false;
		} else if ( $_POST['fieldtype'] == 'selectbox' && empty($_POST['select_option'][0]) ) {
			$message = __('Select box field types require at least one option. Please add options below.');	
			return false;	
		} else if ( $_POST['fieldtype'] == 'checkbox' && empty($_POST['checkbox_option'][0]) ) {
			$message = __('Checkbox field types require at least one option. Please add options below.');	
			return false;		
		} else {
			return true;
		}
	}
	
	function get_type( $field_id ) {
		global $wpdb, $bp_xprofile_table_name_fields;

		if ( $field_id ) {
			$sql = $wpdb->prepare("SELECT type FROM $bp_xprofile_table_name_fields WHERE id = %d", $field_id);

			if ( !$field_type = $wpdb->get_var($sql) )
				return false;
		
			return $field_type;
		}
		
		return false;
	}
	
	function delete_for_group( $group_id ) {
		global $wpdb, $bp_xprofile_table_name_fields;

		if ( $group_id ) {
			$sql = $wpdb->prepare("DELETE FROM $bp_xprofile_table_name_fields WHERE group_id = %d", $group_id);

			if ( $wpdb->get_var($sql) === false ) {
				return false;
			}
			
			return true;
		}
		
		return false;
	}
}


Class BP_XProfile_ProfileData {
	var $id;
	var $user_id;
	var $field_id;
	var $value;
	var $last_updated;
	var $table_name_data;
	var $table_name_fields;
		
	function bp_xprofile_profiledata( $field_id = null, $user_id = null ) {
		global $bp_xprofile_table_name_data, $bp_xprofile_table_name_fields;

		$this->table_name_data = $bp_xprofile_table_name_data;
		$this->table_name_fields = $bp_xprofile_table_name_fields;
		
		if ( $field_id ) {
			$this->populate( $field_id, $user_id );
		}
	}

	function populate( $field_id, $user_id )  {
		global $wpdb, $userdata;
		
		if ( is_null($user_id) )
			$user_id = $userdata->ID;
		
		$sql = $wpdb->prepare("SELECT * FROM $this->table_name_data	WHERE field_id = %d AND user_id = %d", $field_id, $user_id);

		if ( $profiledata = $wpdb->get_row($sql) ) {
			$this->id = $profiledata->id;
			$this->user_id = $profiledata->user_id;
			$this->field_id = $profiledata->field_id;
			$this->value = $profiledata->value;
			$this->last_updated = $profiledata->last_updated;
		}
	}
	
	function exists() {
		global $wpdb, $userdata;
		
		// check to see if there is data already for the user.
		$sql = $wpdb->prepare("SELECT id FROM $this->table_name_data WHERE user_id = %d AND field_id = %d", $userdata->ID, $this->field_id);

		if ( !$wpdb->get_row($sql) ) 
			return false;

		return true;		
	}
		
	function is_valid_field() {
		global $wpdb;
		
		// check to see if this data is actually for a valid field.
		$sql = $wpdb->prepare("SELECT id FROM $this->table_name_fields WHERE id = %d", $this->field_id);

		if ( !$wpdb->get_row($sql) ) 
			return false;
		
		return true;
	}

	function save() {
		global $wpdb, $userdata;

		if ( $this->is_valid_field() ) {
			if ( $this->exists() && $this->value != '' ) {
				$sql = $wpdb->prepare("UPDATE $this->table_name_data SET value = %s, last_updated = %d WHERE user_id = %d AND field_id = %d", $this->value, $this->last_updated, $this->user_id, $this->field_id);
			} else if ( $this->exists() and $this->value == '' ) {
				// Data removed, delete the entry.
				$this->delete();
			} else {
				$sql = $wpdb->prepare("INSERT INTO $this->table_name_data (user_id, field_id, value, last_updated) VALUES (%d, %d, %s, %d)", $this->user_id, $this->field_id, $this->value, $this->last_updated);
			}
						
			if ( $wpdb->query($sql) === false )
				return false;
			
			return true;
		} else {
			return false;
		}
	}

	function delete() {
		global $wpdb;
		
		$sql = $wpdb->prepare("DELETE FROM $this->table_name_data WHERE field_id = %d AND user_id = %d", $this->field_id, $this->user_id);

		if ( $wpdb->query($sql) === false )
			return false;
		
		return true;
	}
	
	/** Static Functions **/
	
	function get_value( $field_id ) {
		global $wpdb, $userdata, $bp_xprofile_table_name_data;

		$sql = $wpdb->prepare("SELECT * FROM $bp_xprofile_table_name_data WHERE field_id = %d AND user_id = %d", $field_id, $userdata->ID);

		if ( $profileData = $wpdb->get_row($sql) ) {
			return $profileData->value;
		} else {
			return false;
		}
	}
	
	function delete_for_field( $field_id ) {
		global $wpdb, $userdata, $bp_xprofile_table_name_data;

		$sql = $wpdb->prepare("DELETE FROM $bp_xprofile_table_name_data WHERE field_id = %d", $field_id);

		if ( $wpdb->query($sql) === false )
			return false;
		
		return true;
	}
	
}


Class BP_XProfile_Picture {
	var $path;	
	
	var $file;	
	var $filename;
	var $width;
	var $height;
	var $filesize;
	var $thumb_filename;
	var $html;
	
	var $thumb_max_dimension = 256; // px
	var $error_message;
	
	function bp_xprofile_picture( $file)  {
		global $profile_picture_path;
		
		$this->path = ABSPATH . $profile_picture_path;
		
		if(is_array($file))
			$this->file = $file;
		else
			$this->populate($file);
	}
	
	function populate( $filename ) {	
		$this->filename = $filename;
		$this->width = $this->get_width();
		$this->height = $this->get_height();
		$this->filesize = $this->get_size();
		$this->html = $this->get_html();
		
		$this->thumb_filename = $this->get_thumb_filename();	
	}
	
	function upload() {
		if( !$this->file ) {
			$this->error_message = 'The image did not upload correctly, please try again.';
			return false;
		}
		
		// If we don't override the error feedback, people will see the robot-like
		// PHP generated error messages. Let's give people something more pleasant.
		$upload_error_strings = array( false,
			__( "The image you uploaded exceeds the maximum file size, please try uploading a smaller version." ),
			__( "The image you uploaded exceeds the maximum file size, please try uploading a smaller version." ),
			__( "The upload was interrupted. Please try uploading your image again." ),
			__( "You left the upload field blank. Please provide a profile image to upload." ),
			__( "There was a problem with temporary folder settings. Please contact the site administrator." ),
			__( "There was a problem with folder permissions. Please contact the site administrator." )
		);

		$uploads = array(
			"url" => trailingslashit( get_option('siteurl')) . 'files/profilepics',
			"error" => false
		);
				
		// Upload the image using the built in WP upload function.			
		$image = wp_handle_upload( $this->file, 
					array( "action" => "save", 
						   "upload_error_strings" => $upload_error_strings,
						   "uploads" => $uploads )
				 );

		// If there were errors uploading, display the message.
		if ( isset($image['error']) ) {
			$this->error_message = $image['error'];
			return false;
		} else {
			$filename = explode( "/", $image['file'] );
			$filename = $filename[count($filename) - 1];
			
			rename( $image['file'], $this->path . "/" . $filename );

			$this->populate($filename);
		
			// No error, lets make a thumbnail..
			$this->create_thumb();	
		}
		
		return true;
	}
	
	function delete() {
		if($this->filename == 'none.gif')
			return false;
			
		$current = $this->get_current();
		
		if ( $this->filename == $current['picture'] ) {
			update_option( 'profile_picture', 'none.gif' );
			update_option( 'profile_picture_thumbnail', 'none.gif' );
		}
		
		if ( file_exists( $this->path . "/" . $this->filename ) ) {
			unlink( $this->path . "/" . $this->filename );
		}
		
		if( file_exists( $this->path . "/" . $this->thumb_filename ) ) {
			unlink( $this->path . "/" . $this->thumb_filename );
		}

		return true;
	}

	function set( $option_name ) {
		update_option( $option_name, $this->filename );
	}
	
	function create_thumb() {
		$thumb = image_resize( $this->path . "/" . $this->filename, $this->thumb_max_dimension, '', false, 'thumbnail' );
		$thumb = apply_filters( 'wp_create_thumbnail', $thumb );

		if ( !@file_exists($thumb) ) {
			$this->error_message = 'There was a problem uploading that image. Please try again.';
			return false;

			// Remove the main image if the thumbnail failed, we don't want
			// profile images with no thumbnails.
			unlink( $this->path . "/" . $this->filename );
		} else {
			$filename = explode( "/", $thumb );
			$this->thumb_filename = $filename[count($filename)-1];
		}
	}
		
	function get_width() {
		$dimensions = getimagesize( $this->path . '/' . $this->filename );
		return $dimensions[0];
	}
	
	function get_height() {
		$dimensions = getimagesize( $this->path . '/' . $this->filename );
		return $dimensions[1];		
	}
	
	function get_size() {
		$size = filesize( $this->path . '/' . $this->filename );
		return $size;		
	}
	
	function get_html() {
		global $profile_picture_base;
		
		return '<img src="' . $profile_picture_base . '/' . $this->filename . '" alt="Profile Pic"
				 height="' . $this->height . '" width="' . $this->width . '" />';
	}
	
	function get_thumb_filename() {
		if ( !strstr( $this->filename, 'thumbnail' ) ) {
			if ( $this->filename == "none.gif" ) {
				$thumbnail_filename = "none.gif";
			} else {
				$thumbnail_filename = explode( '.', $this->filename );
				$thumbnail_filename = $thumbnail_filename[0] . '-thumbnail' . '.' . $thumbnail_filename[1];
			}
			
			return $thumbnail_filename;
		}
		
		return false;
	}
	
	/** Static Functions **/
	
	function get_all($folder) {
		global $profile_picture_path;
		
		if ( !is_dir($folder) ) {
			return false;
		}
		
		$folder = dir($folder);

		while ( $file = $folder->read() ) {
			if ( strpos( $file, 'thumbnail' ) ) {
				$pictures[] = array( "thumbnail" => $file, "file" => str_replace( '-thumbnail', '', $file ) );
			}
		}

		$folder->close();
		return $pictures;
	}
	
	function get_current() {
		$current['picture'] = get_option('profile_picture');
		$current['thumbnail'] = get_option('profile_picture_thumbnail');
		
		return $current;
	}

}


?>