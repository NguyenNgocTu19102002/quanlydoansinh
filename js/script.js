function loadClassData(id, ten_lop, nganh) {
    document.getElementById('class_id').value = id;
    document.getElementById('ten_lop').value = ten_lop;
    document.getElementById('nganh').value = nganh;

    // Lấy danh sách Huynh Trưởng hiện tại của lớp
    fetch('get_class_teachers.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            const teacherList = document.getElementById('teacher-list');
            teacherList.innerHTML = '';
            if (data.length > 0) {
                data.forEach(teacher => {
                    addTeacherRow(teacher.teacher_id, teacher.vai_tro);
                });
            } else {
                addTeacherRow();
            }
        });
}

function addTeacherRow(teacher_id = '', vai_tro = '') {
    const teacherList = document.getElementById('teacher-list');
    const row = document.createElement('div');
    row.className = 'teacher-row';
    
    // Lấy danh sách Huynh Trưởng từ biến toàn cục allTeachers
    let options = '<option value="">Chọn Huynh Trưởng</option>';
    window.allTeachers.forEach(teacher => {
        options += `<option value="${teacher.id}" ${teacher_id == teacher.id ? 'selected' : ''}>${teacher.ho_ten}</option>`;
    });

    row.innerHTML = `
        <select name="huynh_truong[]" class="form-control">${options}</select>
        <input type="text" name="vai_tro[]" value="${vai_tro}" placeholder="Vai trò (Trưởng/Phó)" class="form-control">
        <span class="remove-teacher" onclick="this.parentElement.remove()">&times;</span>
    `;
    teacherList.appendChild(row);
}