<dialog id="adminDialog">
    <div class="modal-head">
        <div>
            <small>VODNOX CONSOLE</small>
            <h2 id="adminDialogTitle"></h2>
        </div>
        <button class="icon-btn" type="button" id="adminDialogClose" aria-label="@lang('admin.close')">&times;</button>
    </div>
    <form id="adminDialogForm" method="POST" action="" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="_method" value="POST" id="adminDialogMethod">
        <div id="adminDialogBody" style="padding:25px 26px;max-height:65vh;overflow:auto"></div>
        <div class="modal-foot">
            <button type="button" class="btn-quiet" id="adminDialogCancel">@lang('admin.cancel')</button>
            <button type="submit" class="btn-primary">@lang('admin.save')</button>
        </div>
    </form>
</dialog>
