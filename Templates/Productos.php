<h1>Listado de Productos</h1>
<table>
  <thead>
    <tr>
      <th>id</th>
      <th>Nombre</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($productos as $producto): ?>
      <tr>
        <td><?php echo $producto->id ?></td>
        <td><?php echo $producto->nombre ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
